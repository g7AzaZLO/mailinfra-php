<?php

declare(strict_types=1);

namespace MailInfra\Http;

/**
 * Ответа от API не было: DNS, TCP, TLS, таймаут, обрыв соединения.
 *
 * Наружу не выходит — клиент превращает её в ApiTimeoutException или
 * ApiConnectionException, предварительно решив, можно ли повторить запрос.
 * Отдельный тип нужен именно для этого решения: таймаут и обрыв повторяются
 * только для идемпотентных вызовов.
 */
final class TransportException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly bool $timeout = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Был ли это истёкший таймаут, а не сбой связности.
     */
    public function isTimeout(): bool
    {
        return $this->timeout;
    }
}
