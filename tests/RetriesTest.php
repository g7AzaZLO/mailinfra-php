<?php

declare(strict_types=1);

namespace MailInfra\Tests;

use MailInfra\Exception\ApiConnectionException;
use MailInfra\Exception\ApiTimeoutException;
use MailInfra\Exception\RateLimitException;
use MailInfra\Exception\ServerException;
use MailInfra\Http\TransportException;
use MailInfra\RetryPolicy;
use PHPUnit\Framework\TestCase;

final class RetriesTest extends TestCase
{
    private const API_KEY = 'mi_live_0123456789abcdef0123456789abcdef';

    private function client(StubHttpClient $http, ?RetryPolicy $policy = null): TestableMailInfra
    {
        return new TestableMailInfra(
            apiKey: self::API_KEY,
            baseUrl: 'https://api.test/v1',
            retryPolicy: $policy,
            httpClient: $http,
        );
    }

    private function send(TestableMailInfra $client): void
    {
        $client->emails->send(
            from: 'hello@mail.example.ru',
            to: 'user@example.com',
            subject: 'Привет',
            text: 'Привет!',
        );
    }

    private function sendBatch(TestableMailInfra $client, bool $withoutIdempotencyKey = false): void
    {
        $client->emails->sendBatch(
            [['from' => 'hello@mail.example.ru', 'to' => 'a@example.com', 'subject' => 'Раз', 'text' => '1']],
            withoutIdempotencyKey: $withoutIdempotencyKey,
        );
    }

    /**
     * Повтор идемпотентной отправки уходит с тем же Idempotency-Key — иначе
     * бэкенду нечем распознать дубль и 503 превратился бы в два письма.
     */
    public function testRetryReusesIdempotencyKey(): void
    {
        $http = new StubHttpClient(
            StubHttpClient::error(503, 'unavailable', 'позже'),
            StubHttpClient::acceptedSend(),
        );
        $client = $this->client($http);

        $this->send($client);

        self::assertCount(2, $http->requests);
        $first = $http->requests[0]['headers']['Idempotency-Key'];
        $second = $http->requests[1]['headers']['Idempotency-Key'];
        self::assertSame($first, $second);
        // initialBackoff 0.5 c, jitter в тестах даёт ровно базовую задержку.
        self::assertSame([0.5], $client->slept);
    }

    /**
     * Backoff растёт экспоненциально: 0.5 c → 1 c.
     */
    public function testBackoffGrows(): void
    {
        $http = new StubHttpClient(
            StubHttpClient::error(502, 'bad_gateway', 'позже'),
            StubHttpClient::error(502, 'bad_gateway', 'позже'),
            StubHttpClient::acceptedSend(),
        );
        $client = $this->client($http);

        $this->send($client);

        self::assertSame([0.5, 1.0], $client->slept);
    }

    /**
     * Повторы кончились — отдаём последнюю ошибку, а не крутим бесконечно.
     */
    public function testRetriesAreExhausted(): void
    {
        $http = new StubHttpClient(
            StubHttpClient::error(503, 'unavailable', 'позже'),
            StubHttpClient::error(503, 'unavailable', 'позже'),
            StubHttpClient::error(503, 'unavailable', 'позже'),
        );

        $this->expectException(ServerException::class);

        try {
            $this->send($this->client($http));
        } finally {
            self::assertCount(3, $http->requests, 'первая попытка + два повтора');
        }
    }

    /**
     * Retry-After приоритетнее собственного backoff: сервер знает про свои окна
     * лимитов больше, чем клиент.
     */
    public function testRetryAfterWins(): void
    {
        $http = new StubHttpClient(
            StubHttpClient::error(429, 'rps_exceeded', 'часто', ['Retry-After' => '3']),
            StubHttpClient::acceptedSend(),
        );
        $client = $this->client($http);

        $this->send($client);

        self::assertSame([3.0], $client->slept);
    }

    /**
     * Доверие к Retry-After ограничено: иначе один длинный заголовок подвесил
     * бы вызывающий код на минуты.
     */
    public function testTooLongRetryAfterIsNotAwaited(): void
    {
        $http = new StubHttpClient(
            StubHttpClient::error(429, 'rate_limit_exceeded', 'месячный объём', ['Retry-After' => '3600']),
        );
        $client = $this->client($http);

        try {
            $this->send($client);
            self::fail('Ожидали RateLimitException');
        } catch (RateLimitException $exception) {
            self::assertCount(1, $http->requests);
            self::assertSame([], $client->slept, 'SDK ждал вместо того, чтобы отдать управление');
            self::assertSame(3600, $exception->getRetryAfter());
        }
    }

    /**
     * Батч с ключом повторяется после 5xx с тем же ключом: бэкенд выводит из
     * него ключ каждого письма и уже принятые письма второй раз не принимает.
     */
    public function testBatchWithKeyIsRetriedWithSameKey(): void
    {
        $http = new StubHttpClient(
            StubHttpClient::error(503, 'unavailable', 'позже'),
            StubHttpClient::acceptedBatch(),
        );

        $this->sendBatch($this->client($http));

        self::assertCount(2, $http->requests);
        self::assertSame(
            $http->requests[0]['headers']['Idempotency-Key'],
            $http->requests[1]['headers']['Idempotency-Key'],
        );
    }

    /**
     * Батч без ключа неидемпотентен: повтор после 5xx отправил бы часть писем
     * второй раз.
     */
    public function testBatchWithoutKeyIsNotRetriedOnServerError(): void
    {
        $http = new StubHttpClient(StubHttpClient::error(503, 'unavailable', 'позже'));

        $this->expectException(ServerException::class);

        try {
            $this->sendBatch($this->client($http), withoutIdempotencyKey: true);
        } finally {
            self::assertCount(1, $http->requests);
        }
    }

    /**
     * 429 у батча повторяется даже без ключа: лимитёр отказывает до приёма
     * писем, значит ничего не принято.
     */
    public function testBatchWithoutKeyIsRetriedOnRateLimit(): void
    {
        $http = new StubHttpClient(
            StubHttpClient::error(429, 'rps_exceeded', 'часто', ['Retry-After' => '1']),
            StubHttpClient::acceptedBatch(),
        );

        $this->sendBatch($this->client($http), withoutIdempotencyKey: true);

        self::assertCount(2, $http->requests);
    }

    /**
     * Отмена повторяется после 5xx: дважды письмо не отменится, а если первая
     * попытка дошла, повтор честно ответит 409 email_not_scheduled.
     */
    public function testCancelIsRetriedOnServerError(): void
    {
        $http = new StubHttpClient(
            StubHttpClient::error(503, 'unavailable', 'позже'),
            StubHttpClient::json(
                200,
                '{"data":{"id":"018e1b7a-1111-7000-aaaa-111111111111","status":"CANCELED","scheduled_at":null}}',
            ),
        );

        $this->client($http)->emails->cancel('018e1b7a-1111-7000-aaaa-111111111111');

        self::assertCount(2, $http->requests);
    }

    /**
     * Без Idempotency-Key отправка неидемпотентна: дедуплицировать повтор
     * бэкенду нечем.
     */
    public function testSendWithoutKeyIsNotRetried(): void
    {
        $http = new StubHttpClient(StubHttpClient::error(503, 'unavailable', 'позже'));
        $client = new TestableMailInfra(
            apiKey: self::API_KEY,
            baseUrl: 'https://api.test/v1',
            autoIdempotency: false,
            httpClient: $http,
        );

        $this->expectException(ServerException::class);

        try {
            $this->send($client);
        } finally {
            self::assertCount(1, $http->requests);
        }
    }

    /**
     * Сетевой сбой идемпотентного запроса повторяется: ключ тот же, дубля не будет.
     */
    public function testNetworkErrorIsRetriedForIdempotentRequest(): void
    {
        $http = new StubHttpClient(
            new TransportException('обрыв соединения'),
            new TransportException('обрыв соединения'),
            new TransportException('обрыв соединения'),
        );

        $this->expectException(ApiConnectionException::class);

        try {
            $this->send($this->client($http));
        } finally {
            self::assertCount(3, $http->requests);
        }
    }

    /**
     * Сетевой сбой батча с ключом повторяется: ключ тот же, дублей не будет.
     */
    public function testNetworkErrorIsRetriedForBatchWithKey(): void
    {
        $http = new StubHttpClient(
            new TransportException('обрыв соединения'),
            StubHttpClient::acceptedBatch(),
        );

        $this->sendBatch($this->client($http));

        self::assertCount(2, $http->requests);
    }

    /**
     * Отсутствие ответа не значит, что сервер ничего не принял: обрыв случается
     * и после того, как запрос дошёл и был выполнен. Для батча без ключа это
     * запрет повтора.
     */
    public function testNetworkErrorIsNotRetriedForBatchWithoutKey(): void
    {
        $http = new StubHttpClient(
            new TransportException('обрыв соединения'),
            new TransportException('обрыв соединения'),
        );

        $this->expectException(ApiConnectionException::class);

        try {
            $this->sendBatch($this->client($http), withoutIdempotencyKey: true);
        } finally {
            self::assertCount(1, $http->requests);
        }
    }

    /**
     * Таймаут отличается от прочих сбоев классом и кодом: по ним видно, что
     * дело во времени, а не в связности.
     */
    public function testTimeoutIsClassifiedSeparately(): void
    {
        $http = new StubHttpClient(
            new TransportException('таймаут', timeout: true),
            new TransportException('таймаут', timeout: true),
            new TransportException('таймаут', timeout: true),
        );

        try {
            $this->send($this->client($http));
            self::fail('Ожидали ApiTimeoutException');
        } catch (ApiTimeoutException $exception) {
            // Таймаут — частный случай «ответа не было»: обработчик, ловящий
            // сетевой сбой, обязан ловить и его.
            self::assertInstanceOf(ApiConnectionException::class, $exception);
            self::assertSame('api_timeout', $exception->getErrorCode());
        }
    }
}
