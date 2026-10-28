<?php

declare(strict_types=1);

namespace MailInfra\Model;

use MailInfra\Enum\EmailStatus;
use MailInfra\Internal\Cast;

/**
 * Состояние планирования письма — ответ `cancel()` и `reschedule()`.
 *
 * Только планирование, без тела письма: отмену разрешено вызывать ключу с
 * правом на отправку, и читать содержимое он не обязан уметь.
 *
 * Поля повторяют схему EmailScheduleData из https://docs.mailinfra.ru/openapi/mailinfra-public.json;
 * совпадение состава проверяет SpecParityTest.
 */
final class EmailScheduleData
{
    /**
     * @param string $id Идентификатор письма.
     * @param EmailStatus $status CANCELED после отмены, SCHEDULED после переноса.
     * @param \DateTimeImmutable|null $scheduledAt Время отправки. После переноса
     *                                             на прошедшее время — ближайший
     *                                             момент: письмо уйдёт сейчас.
     */
    public function __construct(
        public readonly string $id,
        public readonly EmailStatus $status,
        public readonly ?\DateTimeImmutable $scheduledAt,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Cast::string($data, 'id'),
            status: Cast::enum($data, 'status', EmailStatus::class),
            scheduledAt: Cast::nullableDateTime($data, 'scheduled_at'),
        );
    }
}
