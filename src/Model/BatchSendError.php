<?php

declare(strict_types=1);

namespace MailInfra\Model;

use MailInfra\Internal\Cast;

/**
 * Ошибка одного письма внутри батча.
 *
 * Батч возвращает 202, даже если часть писем не принята, поэтому отказ
 * приходит не исключением, а вот этой структурой — по письму.
 *
 * Поля повторяют схему BatchSendError из https://docs.mailinfra.ru/openapi/mailinfra-public.json;
 * совпадение состава проверяет SpecParityTest.
 */
final class BatchSendError
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly string $code,
        public readonly string $message,
        public readonly array $details,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            statusCode: Cast::int($data, 'status_code'),
            code: Cast::string($data, 'code'),
            message: Cast::string($data, 'message'),
            details: Cast::map($data, 'details'),
        );
    }
}
