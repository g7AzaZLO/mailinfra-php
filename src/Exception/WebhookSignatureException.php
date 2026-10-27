<?php

declare(strict_types=1);

namespace MailInfra\Exception;

/**
 * Подпись вебхука не прошла проверку.
 *
 * Причина лежит в getReason() отдельно от текста: по ней в логах видно, что
 * чинить — конфиг (`invalid_secret`), отправителя (`malformed_header`,
 * `signature_mismatch`) или обработчик (`invalid_payload`).
 *
 * Не наследник MailInfraException: это не ответ API, а проверка входящего
 * запроса, и смешивать их в одном `catch` было бы ошибкой.
 */
final class WebhookSignatureException extends \RuntimeException
{
    /** Secret пуст. */
    public const REASON_INVALID_SECRET = 'invalid_secret';

    /** Заголовок подписи не разобран. */
    public const REASON_MALFORMED_HEADER = 'malformed_header';

    /** Подпись старше допустимого возраста — возможен replay. */
    public const REASON_TOO_OLD = 'too_old';

    /** Подпись не совпала с телом. */
    public const REASON_SIGNATURE_MISMATCH = 'signature_mismatch';

    /** Тело не является JSON-объектом. */
    public const REASON_INVALID_JSON = 'invalid_json';

    /** Событие пришло не по контракту. */
    public const REASON_INVALID_PAYLOAD = 'invalid_payload';

    public function __construct(
        string $message,
        private readonly string $reason,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Машинная причина отказа — одна из констант REASON_*.
     */
    public function getReason(): string
    {
        return $this->reason;
    }
}
