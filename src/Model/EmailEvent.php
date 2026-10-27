<?php

declare(strict_types=1);

namespace MailInfra\Model;

use MailInfra\Enum\EmailEventType;
use MailInfra\Internal\Cast;

/**
 * Событие жизненного цикла письма.
 *
 * Поля повторяют схему EmailEventPublic из https://docs.mailinfra.ru/openapi/mailinfra-public.json;
 * совпадение состава проверяет SpecParityTest.
 */
final class EmailEvent
{
    /**
     * @param EmailEventType|null $type Тип события; null, если бэкенд завёл
     *                                  новый тип раньше, чем обновился SDK —
     *                                  неизвестное значение не роняет разбор.
     * @param array<string, mixed> $data Специфика события: MX, IP, bounce-детали, URL клика.
     */
    public function __construct(
        public readonly string $id,
        public readonly ?EmailEventType $type,
        public readonly array $data,
        public readonly \DateTimeImmutable $timestamp,
        public readonly ?string $ipAddress,
        public readonly ?string $userAgent,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Cast::string($data, 'id'),
            type: Cast::nullableEnum($data, 'type', EmailEventType::class),
            data: Cast::map($data, 'data'),
            timestamp: Cast::dateTime($data, 'timestamp'),
            ipAddress: Cast::nullableString($data, 'ip_address'),
            userAgent: Cast::nullableString($data, 'user_agent'),
        );
    }
}
