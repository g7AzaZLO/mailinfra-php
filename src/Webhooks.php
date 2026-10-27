<?php

/**
 * Проверка подписи вебхуков MailInfra.
 *
 * MailInfra подписывает каждый POST заголовком:
 *
 *     X-MailInfra-Signature: t=1715461200,v1=3d4f2e1a…
 *
 * где `v1` — HMAC-SHA256 от `"{t}."` + сырое тело запроса, ключ — secret
 * вебхука (вместе с префиксом `whsec_`). Алгоритм повторяет
 * app.services.webhook_service.sign_payload; вектора подписи общие для бэкенда
 * и всех SDK — webhook-signatures.json (в репозитории SDK — resources/).
 *
 * Использование:
 *
 *     use MailInfra\Webhooks;
 *
 *     $event = Webhooks::constructEvent(
 *         body: file_get_contents('php://input'),
 *         secret: getenv('MAILINFRA_WEBHOOK_SECRET') ?: '',
 *         signatureHeader: $_SERVER['HTTP_X_MAILINFRA_SIGNATURE'] ?? '',
 *     );
 *
 * Важно передавать сырое тело запроса: пересериализованный JSON даст другие
 * байты и подпись не сойдётся. В частности, `$_POST` здесь бесполезен.
 */

declare(strict_types=1);

namespace MailInfra;

use MailInfra\Exception\WebhookSignatureException;
use MailInfra\Model\WebhookEvent;

/**
 * Проверка подписи и разбор события вебхука.
 */
final class Webhooks
{
    /** Заголовок с подписью доставки. */
    public const SIGNATURE_HEADER = 'X-MailInfra-Signature';

    /**
     * Заголовок с идентификатором события; удобен для дедупликации доставок
     * на вашей стороне.
     */
    public const EVENT_ID_HEADER = 'X-MailInfra-Event-Id';

    /**
     * Допустимый возраст подписи, секунды.
     *
     * Бэкенд отклоняет подписи старше 5 минут (SIGNATURE_MAX_AGE_SEC), держим
     * то же окно, чтобы поведение получателя совпадало с ожиданиями отправителя.
     */
    public const DEFAULT_MAX_AGE_SECONDS = 300;

    private function __construct()
    {
    }

    /**
     * Считает hex-подпись тела — та же функция, что на бэкенде.
     *
     * Нужна в первую очередь тестам и локальной отладке обработчика: позволяет
     * собрать корректный X-MailInfra-Signature без реального вебхука.
     */
    public static function sign(string $body, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', "{$timestamp}." . $body, $secret);
    }

    /**
     * Проверяет подпись, не бросая исключений.
     *
     * Удобно, когда причина отказа неважна и нужен только ответ «пускать или
     * нет». Если причина нужна для логов — берите constructEvent().
     *
     * @param int $maxAgeSeconds Допустимый возраст подписи; 0 отключает проверку времени.
     * @param int|null $now Момент отсчёта; null — текущее время (шов для тестов).
     */
    public static function verify(
        string $body,
        string $secret,
        string $signatureHeader,
        int $maxAgeSeconds = self::DEFAULT_MAX_AGE_SECONDS,
        ?int $now = null,
    ): bool {
        try {
            self::assertSignature($body, $secret, $signatureHeader, $maxAgeSeconds, $now);
        } catch (WebhookSignatureException) {
            return false;
        }

        return true;
    }

    /**
     * Проверяет подпись и возвращает типизированное событие.
     *
     * Это рекомендуемый способ: тело нельзя разобрать раньше, чем проверена
     * подпись, иначе обработчик доверяет неаутентифицированным данным.
     *
     * @param int $maxAgeSeconds Допустимый возраст подписи; 0 отключает проверку времени.
     * @param int|null $now Момент отсчёта; null — текущее время (шов для тестов).
     *
     * @throws WebhookSignatureException Если secret пуст, подпись не сошлась,
     *                                   устарела, тело не является JSON-объектом
     *                                   или событие пришло не по контракту.
     *                                   Причина — в getReason().
     */
    public static function constructEvent(
        string $body,
        string $secret,
        string $signatureHeader,
        int $maxAgeSeconds = self::DEFAULT_MAX_AGE_SECONDS,
        ?int $now = null,
    ): WebhookEvent {
        self::assertSignature($body, $secret, $signatureHeader, $maxAgeSeconds, $now);

        try {
            $payload = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new WebhookSignatureException(
                'Тело вебхука не является JSON',
                WebhookSignatureException::REASON_INVALID_JSON,
                $exception,
            );
        }

        if (!\is_array($payload) || array_is_list($payload)) {
            throw new WebhookSignatureException(
                'Тело вебхука не является JSON-объектом',
                WebhookSignatureException::REASON_INVALID_JSON,
            );
        }

        return WebhookEvent::fromArray($payload);
    }

    /**
     * Проверяет secret, заголовок, возраст подписи и саму подпись.
     *
     * @throws WebhookSignatureException
     */
    private static function assertSignature(
        string $body,
        string $secret,
        string $signatureHeader,
        int $maxAgeSeconds,
        ?int $now,
    ): void {
        // Типовой источник пустого секрета — getenv() у незаданной переменной:
        // с ним подпись считается, но не совпадает никогда, и обработчик молча
        // отклоняет каждую доставку вместо того, чтобы сказать про конфиг.
        if ($secret === '') {
            throw new WebhookSignatureException(
                "Пустой secret вебхука: проверьте MAILINFRA_WEBHOOK_SECRET — значение вида 'whsec_…' "
                . 'берётся в дашборде при создании вебхука',
                WebhookSignatureException::REASON_INVALID_SECRET,
            );
        }

        [$timestamp, $provided] = self::parseHeader($signatureHeader);

        if ($maxAgeSeconds > 0 && abs(($now ?? time()) - $timestamp) > $maxAgeSeconds) {
            throw new WebhookSignatureException(
                "Подпись старше {$maxAgeSeconds} с — возможен replay",
                WebhookSignatureException::REASON_TOO_OLD,
            );
        }

        // hash_equals — сравнение за постоянное время: обычное === утекает
        // длину совпавшего префикса и позволяет подобрать подпись побайтово.
        if (!hash_equals(self::sign($body, $secret, $timestamp), $provided)) {
            throw new WebhookSignatureException(
                'Подпись не совпала',
                WebhookSignatureException::REASON_SIGNATURE_MISMATCH,
            );
        }
    }

    /**
     * Разбирает заголовок вида `t=<unix>,v1=<hex>`.
     *
     * @return array{int, string}
     *
     * @throws WebhookSignatureException
     */
    private static function parseHeader(string $signatureHeader): array
    {
        $timestamp = null;
        $signature = null;

        foreach (explode(',', $signatureHeader) as $item) {
            $parts = explode('=', $item, 2);
            if (\count($parts) !== 2) {
                break;
            }
            // Пробелы вокруг частей допускает и HTTP, и нормализующие прокси:
            // без trim заголовок `t=…, v1=…` ронял бы разбор, хотя другие SDK
            // его принимают — один и тот же вебхук доходил бы не везде.
            $key = trim($parts[0]);
            $value = trim($parts[1]);
            if ($key === 't' && preg_match('/^\d+$/', $value) === 1) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1') {
                $signature = $value;
            }
        }

        if ($timestamp === null || $signature === null || $signature === '') {
            throw new WebhookSignatureException(
                \sprintf("Заголовок %s не разобран: ожидается 't=<unix>,v1=<hex>'", self::SIGNATURE_HEADER),
                WebhookSignatureException::REASON_MALFORMED_HEADER,
            );
        }

        return [$timestamp, $signature];
    }
}
