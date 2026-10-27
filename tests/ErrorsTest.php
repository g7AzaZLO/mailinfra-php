<?php

declare(strict_types=1);

namespace MailInfra\Tests;

use MailInfra\Exception\AllRecipientsSuppressedException;
use MailInfra\Exception\AuthenticationException;
use MailInfra\Exception\BadRequestException;
use MailInfra\Exception\ConflictException;
use MailInfra\Exception\DomainNotVerifiedException;
use MailInfra\Exception\IdempotencyInFlightException;
use MailInfra\Exception\MailInfraException;
use MailInfra\Exception\NotFoundException;
use MailInfra\Exception\PermissionDeniedException;
use MailInfra\Exception\RateLimitException;
use MailInfra\Exception\SandboxTrialExhaustedException;
use MailInfra\Exception\ServerException;
use MailInfra\Exception\UnprocessableException;
use MailInfra\RetryPolicy;
use PHPUnit\Framework\TestCase;

final class ErrorsTest extends TestCase
{
    private const API_KEY = 'mi_live_0123456789abcdef0123456789abcdef';

    /**
     * Повторы выключены: 5xx иначе уедет на второй круг и съест заготовленный ответ.
     */
    private function client(StubHttpClient $http): TestableMailInfra
    {
        return new TestableMailInfra(
            apiKey: self::API_KEY,
            baseUrl: 'https://api.test/v1',
            retryPolicy: new RetryPolicy(maxRetries: 0),
            httpClient: $http,
        );
    }

    private function send(StubHttpClient $http): void
    {
        $this->client($http)->emails->send(
            from: 'hello@mail.example.ru',
            to: 'user@example.com',
            subject: 'Привет',
            text: 'Привет!',
        );
    }

    /**
     * Каждый статус попадает в свой класс: по нему ловят, когда конкретная
     * причина неважна.
     *
     * @dataProvider statuses
     *
     * @param class-string<MailInfraException> $expected
     */
    public function testErrorsByStatus(int $status, string $expected): void
    {
        $http = new StubHttpClient(StubHttpClient::error($status, 'some_code', 'сообщение'));

        try {
            $this->send($http);
            self::fail("Ожидали {$expected}");
        } catch (MailInfraException $exception) {
            self::assertInstanceOf($expected, $exception);
            self::assertSame($status, $exception->getStatusCode());
            self::assertSame('req-77', $exception->getRequestId());
        }
    }

    /**
     * @return iterable<string, array{int, class-string<MailInfraException>}>
     */
    public static function statuses(): iterable
    {
        yield '400' => [400, BadRequestException::class];
        yield '401' => [401, AuthenticationException::class];
        yield '403' => [403, PermissionDeniedException::class];
        yield '404' => [404, NotFoundException::class];
        yield '409' => [409, ConflictException::class];
        yield '422' => [422, UnprocessableException::class];
        yield '429' => [429, RateLimitException::class];
        yield '500' => [500, ServerException::class];
    }

    /**
     * Конкретная причина ловится и сама по себе, и через свою категорию: оба
     * уровня обещаны документацией.
     *
     * @dataProvider codes
     *
     * @param class-string<MailInfraException> $expected
     * @param class-string<MailInfraException> $category
     */
    public function testErrorsByCode(int $status, string $code, string $expected, string $category): void
    {
        $http = new StubHttpClient(StubHttpClient::error($status, $code, 'сообщение'));

        try {
            $this->send($http);
            self::fail("Ожидали {$expected}");
        } catch (MailInfraException $exception) {
            self::assertInstanceOf($expected, $exception);
            self::assertInstanceOf($category, $exception);
            self::assertSame($code, $exception->getErrorCode());
        }
    }

    /**
     * @return iterable<string, array{int, string, class-string<MailInfraException>, class-string<MailInfraException>}>
     */
    public static function codes(): iterable
    {
        yield 'domain_not_verified' => [
            422, 'domain_not_verified', DomainNotVerifiedException::class, UnprocessableException::class,
        ];
        yield 'all_recipients_suppressed' => [
            422, 'all_recipients_suppressed', AllRecipientsSuppressedException::class, UnprocessableException::class,
        ];
        yield 'sandbox_trial_exhausted' => [
            403, 'sandbox_trial_exhausted', SandboxTrialExhaustedException::class, PermissionDeniedException::class,
        ];
        yield 'idempotency_in_flight' => [
            409, 'idempotency_in_flight', IdempotencyInFlightException::class, ConflictException::class,
        ];
    }

    /**
     * 409 idempotency_in_flight не ретраится: параллельный запрос с тем же
     * ключом ещё выполняется, и правильная реакция — отдать управление
     * вызывающему.
     */
    public function testIdempotencyInFlightIsNotRetried(): void
    {
        $http = new StubHttpClient(StubHttpClient::error(409, 'idempotency_in_flight', 'выполняется'));

        try {
            $this->send($http);
            self::fail('Ожидали IdempotencyInFlightException');
        } catch (IdempotencyInFlightException) {
            self::assertCount(1, $http->requests);
        }
    }

    /**
     * details.code уточняет причину внутри статуса и приоритетнее
     * верхнеуровневого кода: 429 от лимитёра RPS приходит именно так.
     */
    public function testDetailCodeWins(): void
    {
        $http = new StubHttpClient(StubHttpClient::json(
            429,
            '{"error":{"code":"rate_limit_exceeded","message":"часто","details":{"code":"rps_exceeded","limit":10}}}',
            ['Retry-After' => '1'],
        ));

        try {
            $this->send($http);
            self::fail('Ожидали RateLimitException');
        } catch (RateLimitException $exception) {
            self::assertSame('rps_exceeded', $exception->getErrorCode());
            self::assertSame(1, $exception->getRetryAfter());
            self::assertSame(10, $exception->getDetails()['limit']);
        }
    }

    /**
     * Новый код на бэкенде не должен ломать старый SDK: неизвестная причина
     * деградирует до класса по статусу.
     */
    public function testUnknownCodeFallsBackToStatus(): void
    {
        $http = new StubHttpClient(StubHttpClient::error(422, 'brand_new_reason', 'что-то новое'));

        try {
            $this->send($http);
            self::fail('Ожидали UnprocessableException');
        } catch (UnprocessableException $exception) {
            self::assertSame('brand_new_reason', $exception->getErrorCode());
        }
    }

    /**
     * Прокси и балансировщики умеют отвечать HTML — это не должно превращаться
     * в фатальную ошибку разбора.
     */
    public function testNonJsonErrorBody(): void
    {
        $http = new StubHttpClient(new \MailInfra\Http\HttpResponse(
            502,
            ['content-type' => 'text/html'],
            '<html><body>502 Bad Gateway</body></html>',
        ));

        try {
            $this->send($http);
            self::fail('Ожидали ServerException');
        } catch (ServerException $exception) {
            self::assertSame('unknown_error', $exception->getErrorCode());
            self::assertSame(502, $exception->getStatusCode());
        }
    }

    public function testValidationFields(): void
    {
        $http = new StubHttpClient(StubHttpClient::json(422, '{"error":{"code":"request_validation_failed",
            "message":"не прошло валидацию","details":{"fields":[
            {"field":"subject","message":"слишком длинная","code":"too_long"},{"broken":true}]}}}'));

        try {
            $this->send($http);
            self::fail('Ожидали UnprocessableException');
        } catch (UnprocessableException $exception) {
            $fields = $exception->getFields();
            // Мусорная запись отбрасывается: обработчик ошибки не должен падать
            // из-за неожиданной формы деталей.
            self::assertCount(1, $fields);
            self::assertSame('subject', $fields[0]['field']);
            self::assertSame('too_long', $fields[0]['code'] ?? null);
        }
    }

    public function testExceptionStringCarriesDiagnostics(): void
    {
        $exception = new MailInfraException('Домен не подтверждён', 422, 'domain_not_verified', [], 'req-77');

        self::assertSame(
            'Домен не подтверждён (code=domain_not_verified, status=422, request_id=req-77)',
            (string) $exception,
        );
    }
}
