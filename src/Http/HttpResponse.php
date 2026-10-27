<?php

declare(strict_types=1);

namespace MailInfra\Http;

/**
 * Ответ транспорта: статус, заголовки и сырое тело.
 *
 * Тело сознательно не разбирается здесь: JSON от него ждёт клиент, а прокси и
 * балансировщики умеют отвечать HTML, и маппинг ошибок должен это пережить.
 */
final class HttpResponse
{
    /**
     * @param array<string, string> $headers Заголовки ответа; имена приведены к нижнему регистру.
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    /**
     * Значение заголовка без оглядки на регистр имени.
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * Успешен ли ответ (2xx).
     */
    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }
}
