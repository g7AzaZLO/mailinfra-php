<?php

declare(strict_types=1);

namespace MailInfra\Tests;

use MailInfra\Http\HttpClientInterface;
use MailInfra\Http\HttpResponse;
use MailInfra\Http\TransportException;

/**
 * Транспорт-заглушка: отдаёт заготовленные ответы по очереди и записывает
 * запросы. Сети в тестах нет вовсе — проверяется ровно то, что собрал SDK.
 */
final class StubHttpClient implements HttpClientInterface
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $requests = [];

    /** @var list<HttpResponse|TransportException> */
    private array $responses;

    /**
     * @param HttpResponse|TransportException ...$responses Ответы по порядку;
     *                                                      исключение означает,
     *                                                      что ответа не было.
     */
    public function __construct(HttpResponse|TransportException ...$responses)
    {
        $this->responses = array_values($responses);
    }

    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        ?float $timeout,
    ): HttpResponse {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        $next = array_shift($this->responses);
        if ($next === null) {
            throw new \LogicException(
                \sprintf('Запрос #%d (%s %s) — заготовленные ответы кончились', \count($this->requests), $method, $url),
            );
        }
        if ($next instanceof TransportException) {
            throw $next;
        }

        return $next;
    }

    /**
     * Последний записанный запрос.
     *
     * @return array{method: string, url: string, headers: array<string, string>, body: ?string}
     */
    public function last(): array
    {
        if ($this->requests === []) {
            throw new \LogicException('Транспорт не получил ни одного запроса');
        }

        return $this->requests[\count($this->requests) - 1];
    }

    /**
     * Тело последнего запроса, разобранное из JSON.
     *
     * @return array<string, mixed>
     */
    public function lastJson(): array
    {
        $body = $this->last()['body'] ?? '';

        return json_decode((string) $body, true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * Query-параметры последнего запроса.
     *
     * @return array<string, string>
     */
    public function lastQuery(): array
    {
        $query = parse_url($this->last()['url'], \PHP_URL_QUERY);
        if (!\is_string($query)) {
            return [];
        }
        parse_str($query, $parsed);

        /** @var array<string, string> $parsed */
        return $parsed;
    }

    /**
     * Успешный JSON-ответ.
     *
     * @param array<string, string> $headers
     */
    public static function json(int $status, string $body, array $headers = []): HttpResponse
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = $value;
        }
        $normalized['content-type'] = 'application/json';

        return new HttpResponse($status, $normalized, $body);
    }

    /**
     * Успешный ответ на отправку письма.
     */
    public static function acceptedSend(string $id = '018e1b7a-1111-7000-aaaa-111111111111'): HttpResponse
    {
        return self::json(202, \sprintf('{"data":{"id":"%s","status":"QUEUED","skipped_recipients":[]}}', $id));
    }

    /**
     * Ответ в конверте ошибок MailInfra.
     *
     * @param array<string, string> $headers
     */
    public static function error(int $status, string $code, string $message, array $headers = []): HttpResponse
    {
        $body = json_encode(
            ['error' => ['code' => $code, 'message' => $message], 'request_id' => 'req-77'],
            \JSON_UNESCAPED_UNICODE,
        );

        return self::json($status, (string) $body, $headers + ['x-request-id' => 'req-77']);
    }
}
