<?php

declare(strict_types=1);

namespace MailInfra\Model;

use MailInfra\Enum\EmailStatus;
use MailInfra\Internal\Cast;

/**
 * Результат одного письма внутри батча.
 *
 * Поля повторяют схему BatchSendResult из https://docs.mailinfra.ru/openapi/mailinfra-public.json;
 * совпадение состава проверяет SpecParityTest.
 */
final class BatchSendResult
{
    /**
     * @param int $index Позиция письма в исходном списке.
     * @param string|null $id Идентификатор принятого письма; null, если письмо отклонено.
     * @param EmailStatus|null $status Статус принятого письма; null, если письмо отклонено.
     * @param list<array<string, string>> $skippedRecipients Отброшенные получатели с причиной.
     * @param BatchSendError|null $error Причина отказа; null, если письмо принято.
     */
    public function __construct(
        public readonly int $index,
        public readonly ?string $id,
        public readonly ?EmailStatus $status,
        public readonly array $skippedRecipients,
        public readonly ?BatchSendError $error,
    ) {
    }

    /**
     * Принято ли письмо. Удобнее, чем сравнивать `$error` с null на каждой итерации.
     */
    public function isAccepted(): bool
    {
        return $this->error === null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $error = $data['error'] ?? null;

        return new self(
            index: Cast::int($data, 'index'),
            id: Cast::nullableString($data, 'id'),
            status: Cast::nullableEnum($data, 'status', EmailStatus::class),
            skippedRecipients: Cast::stringMapList($data, 'skipped_recipients'),
            error: \is_array($error) ? BatchSendError::fromArray($error) : null,
        );
    }
}
