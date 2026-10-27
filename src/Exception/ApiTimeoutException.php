<?php

declare(strict_types=1);

namespace MailInfra\Exception;

/**
 * Запрос не успел уложиться в таймаут.
 *
 * Наследник ApiConnectionException: ответа не было в обоих случаях, и
 * обработчик, ловящий сетевой сбой, обязан ловить и таймаут. Отличается код
 * (`api_timeout`) — по нему в логах видно, что дело во времени, а не в связности.
 */
class ApiTimeoutException extends ApiConnectionException
{
    public function __construct(
        string $message = 'Истёк таймаут запроса к API MailInfra',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 'api_timeout', $previous);
    }
}
