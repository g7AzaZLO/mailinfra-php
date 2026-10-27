<?php

declare(strict_types=1);

namespace MailInfra\Tests;

use MailInfra\Enum\Environment;
use MailInfra\Exception\MalformedResponseException;
use MailInfra\MailInfra;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private const API_KEY = 'mi_live_0123456789abcdef0123456789abcdef';

    protected function tearDown(): void
    {
        unset($_ENV[MailInfra::API_KEY_ENV_VAR], $_ENV[MailInfra::BASE_URL_ENV_VAR]);
    }

    public function testRequiresApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MailInfra(apiKey: null, httpClient: new StubHttpClient());
    }

    public function testReadsKeyFromEnvironment(): void
    {
        $_ENV[MailInfra::API_KEY_ENV_VAR] = self::API_KEY;

        $client = new MailInfra(httpClient: new StubHttpClient());

        self::assertSame(Environment::Live, $client->environment);
    }

    /**
     * @dataProvider keyPrefixes
     */
    public function testDetectsEnvironment(string $apiKey, Environment $expected): void
    {
        $client = new MailInfra(apiKey: $apiKey, httpClient: new StubHttpClient());

        self::assertSame($expected, $client->environment);
    }

    /**
     * @return iterable<string, array{string, Environment}>
     */
    public static function keyPrefixes(): iterable
    {
        yield 'live' => ['mi_live_abc', Environment::Live];
        // Legacy-ключ, выданный до фикса генератора, обязан работать.
        yield 'legacy live' => ['mi_LIVE_abc', Environment::Live];
        yield 'test' => ['mi_test_abc', Environment::Test];
        yield 'legacy test' => ['mi_TEST_abc', Environment::Test];
    }

    public function testRejectsUnknownKeyPrefix(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MailInfra(apiKey: 'sk_live_whatever', httpClient: new StubHttpClient());
    }

    /**
     * Тестовый ключ в проде не отправит ни одного письма, а рабочий в тестах
     * отправит настоящие — поэтому несовпадение ловится до первого запроса.
     */
    public function testRejectsWrongEnvironment(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MailInfra(
            apiKey: self::API_KEY,
            requireEnvironment: Environment::Test,
            httpClient: new StubHttpClient(),
        );
    }

    public function testBaseUrlFromEnvironmentLosesTrailingSlash(): void
    {
        $_ENV[MailInfra::BASE_URL_ENV_VAR] = 'https://stage.example.ru/v1/';

        $client = new MailInfra(apiKey: self::API_KEY, httpClient: new StubHttpClient());

        // Иначе путь ресурса дал бы "//emails".
        self::assertSame('https://stage.example.ru/v1', $client->baseUrl);
    }

    public function testSendsAuthAndUserAgent(): void
    {
        $http = new StubHttpClient(StubHttpClient::acceptedSend());
        $client = new MailInfra(apiKey: self::API_KEY, baseUrl: 'https://api.test/v1', httpClient: $http);

        $client->emails->send(from: 'hello@mail.example.ru', to: 'user@example.com', subject: 'Т', text: 'т');

        $headers = $http->last()['headers'];
        self::assertSame('Bearer ' . self::API_KEY, $headers['Authorization']);
        self::assertStringStartsWith('mailinfra-php/' . MailInfra::VERSION, $headers['User-Agent']);
        self::assertSame('application/json', $headers['Content-Type']);
    }

    public function testUserAgentSuffix(): void
    {
        $http = new StubHttpClient(StubHttpClient::acceptedSend());
        $client = new MailInfra(
            apiKey: self::API_KEY,
            baseUrl: 'https://api.test/v1',
            httpClient: $http,
            userAgentSuffix: 'acme-billing/2.1',
        );

        $client->emails->send(from: 'hello@mail.example.ru', to: 'user@example.com', subject: 'Т', text: 'т');

        self::assertStringEndsWith('acme-billing/2.1', $http->last()['headers']['User-Agent']);
    }

    /**
     * Успешный ответ без поля data — это сломанный контракт, а не пустой
     * результат: молча вернув пустоту, SDK превратил бы поломку API в
     * непонятную ошибку строками ниже.
     */
    public function testMalformedSuccessResponse(): void
    {
        $http = new StubHttpClient(StubHttpClient::json(202, '{"id":"018e1b7a"}'));
        $client = new MailInfra(apiKey: self::API_KEY, baseUrl: 'https://api.test/v1', httpClient: $http);

        $this->expectException(MalformedResponseException::class);

        $client->emails->send(from: 'hello@mail.example.ru', to: 'user@example.com', subject: 'Т', text: 'т');
    }
}
