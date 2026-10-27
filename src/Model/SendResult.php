<?php

declare(strict_types=1);

namespace MailInfra\Model;

use MailInfra\Enum\EmailStatus;
use MailInfra\Internal\Cast;

/**
 * Результат отправки одного письма.
 *
 * Поля id/status/skipped_recipients повторяют схему EmailSendResponseData из
 * https://docs.mailinfra.ru/openapi/mailinfra-public.json; остальное — метаданные запроса, которых в
 * теле ответа нет.
 */
final class SendResult
{
    /**
     * @param string $id Идентификатор письма в MailInfra.
     * @param EmailStatus $status Статус сразу после приёма: QUEUED либо
     *                            SIMULATED для sandbox-ключа mi_test_….
     * @param list<array<string, string>> $skippedRecipients Получатели, которых
     *                                    API отбросил (например, адрес в списке
     *                                    подавления), с причиной в значении.
     * @param bool $replayed true, если это идемпотентный повтор: письмо было
     *                       принято предыдущим запросом с тем же
     *                       Idempotency-Key, повторной отправки не произошло.
     * @param string|null $idempotencyKey Ключ, с которым ушёл запрос. Стоит
     *                                    залогировать: с ним повтор гарантированно
     *                                    не создаст второе письмо.
     * @param string|null $requestId Идентификатор запроса для обращений в поддержку.
     */
    public function __construct(
        public readonly string $id,
        public readonly EmailStatus $status,
        public readonly array $skippedRecipients,
        public readonly bool $replayed,
        public readonly ?string $idempotencyKey,
        public readonly ?string $requestId,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(
        array $data,
        bool $replayed,
        ?string $idempotencyKey,
        ?string $requestId,
    ): self {
        return new self(
            id: Cast::string($data, 'id'),
            status: Cast::enum($data, 'status', EmailStatus::class),
            skippedRecipients: Cast::stringMapList($data, 'skipped_recipients'),
            replayed: $replayed,
            idempotencyKey: $idempotencyKey,
            requestId: $requestId,
        );
    }
}
