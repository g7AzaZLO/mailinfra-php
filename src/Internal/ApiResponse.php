<?php

declare(strict_types=1);

namespace MailInfra\Internal;

/**
 * Успешный ответ API вместе с метаданными, которые нужны ресурсам.
 *
 * Заголовки нужны там, где важен не только `data`: `Idempotent-Replayed` при
 * отправке письма и `X-Request-ID` для логов.
 *
 * @internal
 */
final class ApiResponse
{
    /**
     * @param mixed $data Содержимое поля `data` ответа.
     * @param array<string, string> $headers Заголовки ответа в нижнем регистре.
     */
    public function __construct(
        public readonly mixed $data,
        public readonly int $statusCode,
        public readonly array $headers,
        public readonly ?string $requestId,
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
     * Содержимое `data` в виде массива.
     *
     * @return array<string, mixed>
     */
    public function arrayData(): array
    {
        return \is_array($this->data) ? $this->data : [];
    }
}
