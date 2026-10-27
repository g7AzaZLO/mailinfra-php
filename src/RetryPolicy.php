<?php

/**
 * Политика повторов, backoff и разбор `Retry-After`.
 *
 * Логика решения «повторять или нет» вынесена из клиента, чтобы тестироваться
 * без сети и совпадать один-в-один с Python, Node и Go SDK.
 *
 * Ключевое различие между эндпоинтами — идемпотентность:
 *
 * - `POST /emails` идемпотентен: SDK сам ставит `Idempotency-Key` и
 *   переиспользует его на повторах, поэтому ретраить `5xx` и таймауты
 *   безопасно — бэкенд отдаст тот же результат, а не отправит письмо дважды.
 * - `POST /emails/batch` заголовок не принимает, а письма внутри батча
 *   коммитятся по одному. Повтор после `5xx`, таймаута или обрыва соединения
 *   может отправить часть писем второй раз, поэтому для него повторяется
 *   единственная ситуация, в которой заведомо ничего не принято: `429` от
 *   лимитёра, который отказывает до приёма писем.
 * - Отправка без `Idempotency-Key` тоже неидемпотентна: бэкенд дедуплицирует
 *   только по ключу.
 */

declare(strict_types=1);

namespace MailInfra;

/**
 * Настройки повторов.
 */
final class RetryPolicy
{
    /**
     * Статусы, которые имеет смысл повторять.
     *
     * 429 — лимит (бэкенд присылает `Retry-After`), 408/425 — таймаут/ранние
     * данные на стороне прокси, 5xx — временные отказы. 409
     * `idempotency_in_flight` сознательно не здесь: параллельный запрос с тем
     * же ключом ещё выполняется, и корректная реакция — отдать управление
     * вызывающему, а не крутить повторы вслепую.
     *
     * @var list<int>
     */
    public const RETRYABLE_STATUSES = [408, 425, 429, 500, 502, 503, 504];

    /**
     * Для неидемпотентных запросов повторяем только то, где ничего не принято.
     *
     * @var list<int>
     */
    public const RETRYABLE_STATUSES_NON_IDEMPOTENT = [429];

    /**
     * @param int $maxRetries Сколько раз повторить сверх первой попытки. 0 — повторов нет.
     * @param float $initialBackoff Базовая задержка первой паузы, секунды.
     * @param float $backoffFactor Множитель роста задержки между попытками.
     * @param float $maxBackoff Потолок собственной задержки, секунды.
     * @param float $maxRetryAfter Порог доверия к `Retry-After`. Если сервер просит
     *                             подождать дольше — SDK не спит, а отдаёт
     *                             RateLimitException, чтобы вызывающий сам решил,
     *                             ждать или деградировать.
     * @param list<int>|null $retryableStatuses Статусы, повторяемые для идемпотентных запросов.
     * @param list<int>|null $retryableStatusesNonIdempotent То же для неидемпотентных.
     */
    public function __construct(
        public readonly int $maxRetries = 2,
        public readonly float $initialBackoff = 0.5,
        public readonly float $backoffFactor = 2.0,
        public readonly float $maxBackoff = 8.0,
        public readonly float $maxRetryAfter = 60.0,
        public readonly ?array $retryableStatuses = null,
        public readonly ?array $retryableStatusesNonIdempotent = null,
    ) {
        if ($maxRetries < 0) {
            throw new \InvalidArgumentException('maxRetries не может быть отрицательным');
        }
        if ($initialBackoff < 0) {
            throw new \InvalidArgumentException('initialBackoff не может быть отрицательным');
        }
        if ($backoffFactor < 1) {
            throw new \InvalidArgumentException('backoffFactor должен быть >= 1');
        }
    }

    /**
     * Повторять ли запрос с таким статусом.
     *
     * @param bool $idempotent Можно ли безопасно повторить запрос (для send с
     *                         ключом — да, SDK переиспользует Idempotency-Key;
     *                         для батча — нет).
     */
    public function shouldRetryStatus(int $statusCode, bool $idempotent): bool
    {
        $allowed = $idempotent
            ? ($this->retryableStatuses ?? self::RETRYABLE_STATUSES)
            : ($this->retryableStatusesNonIdempotent ?? self::RETRYABLE_STATUSES_NON_IDEMPOTENT);

        return \in_array($statusCode, $allowed, true);
    }

    /**
     * Считает паузу перед следующей попыткой, в секундах.
     *
     * `Retry-After` приоритетнее собственного backoff: сервер знает про свои
     * окна лимитов больше, чем клиент. Но доверие ограничено `maxRetryAfter` —
     * иначе один длинный `Retry-After` подвесил бы вызывающий код на минуты.
     *
     * @param int $attempt Номер завершившейся попытки, начиная с 0.
     * @param float|null $retryAfter Разобранное значение `Retry-After`, если было.
     * @param (callable(): float)|null $jitter Источник случайности в [0, 1);
     *                                         null отключает размытие (тесты).
     *
     * @return float|null Пауза в секундах или null, если ждать не следует
     *                    (сервер просит слишком долго — пусть решает вызывающий).
     */
    public function nextDelay(int $attempt, ?float $retryAfter = null, ?callable $jitter = null): ?float
    {
        if ($retryAfter !== null) {
            return $retryAfter > $this->maxRetryAfter ? null : $retryAfter;
        }

        $base = min($this->maxBackoff, $this->initialBackoff * $this->backoffFactor ** $attempt);
        if ($jitter === null) {
            return $base;
        }

        // Half jitter: половина задержки фиксированная, половина случайная.
        // Полный jitter иногда даёт почти нулевую паузу и повтор улетает в тот
        // же перегруженный сервер; фиксированная пауза без jitter собирает
        // клиентов в синхронные волны.
        return $base / 2 + $jitter() * $base / 2;
    }

    /**
     * Разбирает заголовок `Retry-After`.
     *
     * Бэкенд MailInfra присылает секунды (app.main.register_exception_handlers),
     * но HTTP допускает и дату — её умеют присылать прокси и CDN перед API,
     * поэтому поддерживаются оба формата.
     *
     * @param int|null $now Момент отсчёта для формата-даты; null — текущее время.
     *
     * @return float|null Неотрицательная задержка в секундах или null, если
     *                    заголовка нет либо он не разобран: непонятный
     *                    `Retry-After` — это «нет подсказки», дальше работает
     *                    собственный backoff.
     */
    public static function parseRetryAfter(?string $value, ?int $now = null): ?float
    {
        if ($value === null) {
            return null;
        }

        $raw = trim($value);
        if ($raw === '') {
            return null;
        }

        if (is_numeric($raw)) {
            $seconds = (float) $raw;
            // is_numeric отсекает "NaN"/"Inf", но не научную запись вида "1e400",
            // которая даёт INF. Заголовок от прокси или WAF превратил бы 429 в
            // повтор без паузы — ровно вместо того backoff'а, ради которого он
            // и прислан.
            if (!is_finite($seconds)) {
                return null;
            }

            return max(0.0, $seconds);
        }

        $timestamp = strtotime($raw);
        if ($timestamp === false) {
            return null;
        }

        return max(0.0, (float) ($timestamp - ($now ?? time())));
    }
}
