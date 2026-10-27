<?php

declare(strict_types=1);

namespace MailInfra\Exception;

/**
 * `429` — исчерпан лимит отправки: месячный объём или дневной потолок организации.
 */
class RateLimitException extends MailInfraException
{
    /**
     * @param array<string, mixed> $details
     * @param int|null $retryAfter Секунды до безопасного повтора из заголовка
     *                             `Retry-After`; null, если заголовка не было.
     */
    public function __construct(
        string $message,
        ?int $statusCode = 429,
        string $errorCode = 'rate_limit_exceeded',
        array $details = [],
        ?string $requestId = null,
        private readonly ?int $retryAfter = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $errorCode, $details, $requestId, $previous);
    }

    /**
     * Сколько секунд просил подождать сервер; null, если подсказки не было.
     *
     * SDK ждёт сам, пока пауза укладывается в `maxRetryAfter` политики повторов.
     * Если сервер попросил дольше — клиент не блокирует поток, а отдаёт это
     * исключение: решать, ждать или деградировать, должен вызывающий.
     */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
