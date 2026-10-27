<?php

declare(strict_types=1);

namespace MailInfra\Exception;

/**
 * Не удалось получить ответ: DNS, TCP, TLS, обрыв соединения.
 */
class ApiConnectionException extends MailInfraException
{
    public function __construct(
        string $message = 'Не удалось соединиться с API MailInfra',
        string $errorCode = 'api_connection_error',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, null, $errorCode, [], null, $previous);
    }
}
