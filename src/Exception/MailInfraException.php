<?php

/**
 * Типизированные исключения поверх конверта ошибок MailInfra.
 *
 * API отдаёт любую ошибку одной формой (см. https://docs.mailinfra.ru/errors):
 *
 *     {"error": {"code": "domain_not_verified", "message": "…", "details": {}},
 *      "request_id": "3d6f0dce…"}
 *
 * `code` — стабильный машинный код, его и надо ветвить в коде. Классы подобраны
 * так, чтобы ловить можно было на трёх уровнях: всё (MailInfraException),
 * категория HTTP-статуса (NotFoundException) или конкретная причина
 * (DomainNotVerifiedException). Неизвестный код деградирует до класса по
 * статусу: новый код на бэкенде не роняет старый SDK.
 */

declare(strict_types=1);

namespace MailInfra\Exception;

/**
 * Базовая ошибка SDK. Ловите её, если детали не важны.
 */
class MailInfraException extends \RuntimeException
{
    /**
     * @param string $message Человеко-читаемое сообщение от API (на русском).
     * @param int|null $statusCode HTTP-статус ответа; null для сетевых ошибок.
     * @param string $errorCode Машинный код ошибки (error.code).
     * @param array<string, mixed> $details Безопасные структурированные детали (error.details).
     * @param string|null $requestId Идентификатор запроса для поддержки (он же заголовок X-Request-ID).
     */
    public function __construct(
        string $message,
        private readonly ?int $statusCode = null,
        private readonly string $errorCode = 'unknown_error',
        private readonly array $details = [],
        private readonly ?string $requestId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * HTTP-статус ответа; null для сетевых ошибок и клиентской валидации.
     */
    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    /**
     * Машинный код ошибки — по нему и надо ветвиться, а не по тексту сообщения.
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * Безопасные структурированные детали ошибки.
     *
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    /**
     * Идентификатор запроса — его и надо называть в обращении в поддержку.
     */
    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /**
     * Текст ошибки вместе с кодом, статусом и request_id — всё, что нужно для логов.
     */
    public function __toString(): string
    {
        $parts = [];
        if ($this->errorCode !== '' && $this->errorCode !== 'unknown_error') {
            $parts[] = "code={$this->errorCode}";
        }
        if ($this->statusCode !== null) {
            $parts[] = "status={$this->statusCode}";
        }
        if ($this->requestId !== null && $this->requestId !== '') {
            $parts[] = "request_id={$this->requestId}";
        }

        return $parts === []
            ? $this->getMessage()
            : $this->getMessage() . ' (' . implode(', ', $parts) . ')';
    }
}
