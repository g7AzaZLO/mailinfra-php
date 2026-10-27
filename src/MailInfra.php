<?php

/**
 * Клиент MailInfra: аутентификация по API-ключу проекта, автоматический
 * `Idempotency-Key` на отправке, повторы с экспоненциальным backoff и
 * уважением `Retry-After`, типизированные исключения.
 *
 * Решение «повторять или нет» живёт в RetryPolicy, разбор ошибок — в
 * ErrorFactory, поэтому здесь остаётся только цикл попыток.
 */

declare(strict_types=1);

namespace MailInfra;

use MailInfra\Enum\Environment;
use MailInfra\Exception\ApiConnectionException;
use MailInfra\Exception\ApiTimeoutException;
use MailInfra\Exception\ErrorFactory;
use MailInfra\Exception\MailInfraException;
use MailInfra\Exception\MalformedResponseException;
use MailInfra\Http\CurlHttpClient;
use MailInfra\Http\HttpClientInterface;
use MailInfra\Http\TransportException;
use MailInfra\Internal\ApiResponse;
use MailInfra\Resource\Emails;

/**
 * Клиент Public API MailInfra.
 *
 * ```php
 * $client = new MailInfra(); // ключ из MAILINFRA_API_KEY
 *
 * $result = $client->emails->send(
 *     from: 'hello@mail.example.ru',
 *     to: 'user@example.com',
 *     subject: 'Привет',
 *     html: '<p>Привет!</p>',
 * );
 * echo $result->id, ' ', $result->status->value;
 * ```
 */
class MailInfra
{
    /** Версия SDK; уезжает в `User-Agent` каждого запроса. */
    public const VERSION = '0.1.0';

    /** Переменная окружения с API-ключом проекта. */
    public const API_KEY_ENV_VAR = 'MAILINFRA_API_KEY';

    /** Переменная окружения с базой API. */
    public const BASE_URL_ENV_VAR = 'MAILINFRA_BASE_URL';

    /** Продакшен-база Public API. */
    public const DEFAULT_BASE_URL = 'https://app.mailinfra.ru/v1';

    /** Таймаут одного запроса по умолчанию, секунды. */
    public const DEFAULT_TIMEOUT = 30.0;

    private const REQUEST_ID_HEADER = 'X-Request-ID';

    /**
     * Регистр префикса важен только для распознавания окружения: ключи,
     * выданные до фикса генератора, имеют вид `mi_LIVE_…` (см. legacy-ветку в
     * app.api.api_auth.get_api_context), и такой ключ обязан работать.
     *
     * @var array<string, Environment>
     */
    private const KEY_PREFIXES = [
        'mi_live_' => Environment::Live,
        'mi_LIVE_' => Environment::Live,
        'mi_test_' => Environment::Test,
        'mi_TEST_' => Environment::Test,
    ];

    /** Письма: `send`, `sendBatch`, `get`, `list`, `events`. */
    public readonly Emails $emails;

    /** Действующая политика повторов. */
    public readonly RetryPolicy $retryPolicy;

    /** Ставится ли `Idempotency-Key` автоматически. */
    public readonly bool $autoIdempotency;

    /** База API без завершающего слеша. */
    public readonly string $baseUrl;

    /** Окружение ключа, распознанное по префиксу. */
    public readonly Environment $environment;

    private readonly string $apiKey;
    private readonly ?float $timeout;
    private readonly HttpClientInterface $httpClient;
    private readonly string $userAgent;

    /**
     * @param string|null $apiKey API-ключ проекта; по умолчанию из MAILINFRA_API_KEY.
     * @param string|null $baseUrl База API; по умолчанию из MAILINFRA_BASE_URL либо продакшен.
     * @param float|null $timeout Таймаут одного запроса, секунды. По умолчанию 30;
     *                            null оставляет управление временем вашему транспорту.
     * @param int $maxRetries Сколько раз повторять сверх первой попытки.
     * @param RetryPolicy|null $retryPolicy Полная политика повторов; переопределяет $maxRetries.
     * @param bool $autoIdempotency Ставить ли `Idempotency-Key` автоматически.
     * @param Environment|null $requireEnvironment Требуемое окружение ключа: при
     *                                             несовпадении клиент не создастся.
     * @param HttpClientInterface|null $httpClient Свой транспорт (Guzzle, PSR-18, мок в тестах).
     * @param string|null $userAgentSuffix Дополнение к `User-Agent`.
     *
     * @throws \InvalidArgumentException Если ключа нет или его префикс не
     *                                   распознан: упасть при создании клиента
     *                                   лучше, чем получить 401 на первой отправке.
     */
    public function __construct(
        ?string $apiKey = null,
        ?string $baseUrl = null,
        ?float $timeout = self::DEFAULT_TIMEOUT,
        int $maxRetries = 2,
        ?RetryPolicy $retryPolicy = null,
        bool $autoIdempotency = true,
        ?Environment $requireEnvironment = null,
        ?HttpClientInterface $httpClient = null,
        ?string $userAgentSuffix = null,
    ) {
        $resolvedKey = $apiKey ?? self::env(self::API_KEY_ENV_VAR);
        if ($resolvedKey === null || $resolvedKey === '') {
            throw new \InvalidArgumentException(
                'Не задан API-ключ: передайте apiKey или положите ключ в переменную окружения '
                . self::API_KEY_ENV_VAR . '.',
            );
        }

        $this->apiKey = $resolvedKey;
        $this->environment = self::detectEnvironment($resolvedKey);

        if ($requireEnvironment !== null && $this->environment !== $requireEnvironment) {
            throw new \InvalidArgumentException(\sprintf(
                "Ожидался ключ окружения '%s', а передан '%s'. Проверьте конфигурацию: тестовый ключ "
                . 'в проде не отправит ни одного письма, а рабочий в тестах отправит настоящие.',
                $requireEnvironment->value,
                $this->environment->value,
            ));
        }

        $resolvedBaseUrl = $baseUrl ?? self::env(self::BASE_URL_ENV_VAR) ?? self::DEFAULT_BASE_URL;
        $this->baseUrl = rtrim($resolvedBaseUrl, '/');
        $this->timeout = $timeout;
        $this->autoIdempotency = $autoIdempotency;
        $this->retryPolicy = $retryPolicy ?? new RetryPolicy(maxRetries: $maxRetries);
        $this->httpClient = $httpClient ?? new CurlHttpClient();
        $this->userAgent = self::buildUserAgent($userAgentSuffix);
        $this->emails = new Emails($this);
    }

    /**
     * Выполняет запрос к API с повторами и разбором ошибок.
     *
     * @param array<string, mixed>|null $body Тело запроса.
     * @param array<string, string|int> $query Query-параметры.
     * @param array<string, string> $headers Дополнительные заголовки.
     * @param bool $idempotent Можно ли безопасно повторить запрос.
     *
     * @throws MailInfraException Любая ошибка API или транспорта.
     *
     * @internal Используется ресурсами SDK; в контракт не входит.
     */
    public function request(
        string $method,
        string $path,
        ?array $body = null,
        array $query = [],
        array $headers = [],
        bool $idempotent = true,
    ): ApiResponse {
        $url = $this->baseUrl . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $payload = $body === null
            ? null
            : json_encode($body, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

        $requestHeaders = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => $this->userAgent,
        ] + $headers;
        if ($payload !== null) {
            $requestHeaders['Content-Type'] = 'application/json';
        }

        for ($attempt = 0; ; ++$attempt) {
            try {
                $response = $this->httpClient->send($method, $url, $requestHeaders, $payload, $this->timeout);
            } catch (TransportException $exception) {
                // Отсутствие ответа НЕ означает, что сервер ничего не принял:
                // таймаут чтения и обрыв соединения случаются и после того, как
                // запрос дошёл и был выполнен. Поэтому неидемпотентный запрос
                // (батч, отправка без Idempotency-Key) здесь не повторяется —
                // повтор отправил бы письма второй раз.
                $delay = $idempotent && $attempt < $this->retryPolicy->maxRetries
                    ? $this->retryPolicy->nextDelay($attempt, null, $this->jitter(...))
                    : null;
                if ($delay === null) {
                    throw $exception->isTimeout()
                        ? new ApiTimeoutException(previous: $exception)
                        : new ApiConnectionException(previous: $exception);
                }
                $this->sleepSeconds($delay);
                continue;
            }

            $decoded = self::decode($response->body);
            $requestId = $response->header(self::REQUEST_ID_HEADER);

            if ($response->isSuccessful()) {
                return new ApiResponse(
                    data: self::unwrap($decoded, $response->statusCode, $requestId),
                    statusCode: $response->statusCode,
                    headers: $response->headers,
                    requestId: $requestId,
                );
            }

            $retryAfter = RetryPolicy::parseRetryAfter($response->header('Retry-After'));
            $canRetry = $attempt < $this->retryPolicy->maxRetries
                && $this->retryPolicy->shouldRetryStatus($response->statusCode, $idempotent);
            $delay = $canRetry
                ? $this->retryPolicy->nextDelay($attempt, $retryAfter, $this->jitter(...))
                : null;

            if ($delay === null) {
                throw ErrorFactory::fromResponse(
                    $response->statusCode,
                    $decoded,
                    $requestId,
                    // round, а не (int): на `Retry-After: 0.9` усечение дало бы 0 —
                    // «повторить сейчас», то есть busy-loop у вызывающего.
                    $retryAfter === null ? null : (int) round($retryAfter),
                );
            }

            $this->sleepSeconds($delay);
        }
    }

    /**
     * Пауза между попытками.
     *
     * Отдельный метод — это шов для тестов: подменив его в наследнике, прогон
     * повторов не занимает реального времени.
     */
    protected function sleepSeconds(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) round($seconds * 1_000_000));
        }
    }

    /**
     * Источник случайности для half jitter — второй шов для тестов.
     *
     * Криптостойкость здесь не нужна: это разведение повторов по времени.
     */
    protected function jitter(): float
    {
        return mt_rand() / mt_getrandmax();
    }

    /**
     * Разбирает тело ответа.
     *
     * Прокси и балансировщики умеют отвечать HTML — такой ответ возвращается
     * строкой, и маппинг ошибок это переживает.
     */
    private static function decode(string $body): mixed
    {
        if ($body === '') {
            return null;
        }

        try {
            return json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $body;
        }
    }

    /**
     * Достаёт поле `data` из успешного ответа.
     *
     * @throws MalformedResponseException Если ответ успешен, но не по контракту —
     *                                    иначе поломка API всплыла бы невнятной
     *                                    ошибкой при разборе модели.
     */
    private static function unwrap(mixed $decoded, int $statusCode, ?string $requestId): mixed
    {
        if (!\is_array($decoded) || !\array_key_exists('data', $decoded)) {
            throw new MalformedResponseException(
                "Ответ API не по контракту: ожидался объект с полем 'data'",
                $statusCode,
                'malformed_response',
                [],
                $requestId,
            );
        }

        return $decoded['data'];
    }

    /**
     * Определяет окружение ключа по его префиксу.
     */
    private static function detectEnvironment(string $apiKey): Environment
    {
        foreach (self::KEY_PREFIXES as $prefix => $environment) {
            if (str_starts_with($apiKey, $prefix)) {
                return $environment;
            }
        }

        throw new \InvalidArgumentException(
            "API-ключ MailInfra должен начинаться с 'mi_live_' или 'mi_test_'. "
            . 'Ключ создаётся в дашборде и показывается один раз.',
        );
    }

    /**
     * Собирает `User-Agent` клиента.
     */
    private static function buildUserAgent(?string $suffix): string
    {
        $base = \sprintf('mailinfra-php/%s (PHP/%s)', self::VERSION, \PHP_VERSION);

        return $suffix === null || $suffix === '' ? $base : "{$base} {$suffix}";
    }

    /**
     * Читает переменную окружения.
     *
     * getenv() в некоторых сборках не видит переменные из $_ENV/$_SERVER
     * (variables_order), а putenv() — наоборот, не попадает в $_ENV. Смотрим
     * во все три места, иначе ключ «есть, но не читается» — классическая
     * история для докеризованного PHP-FPM.
     */
    private static function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
