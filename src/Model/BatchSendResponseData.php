<?php

declare(strict_types=1);

namespace MailInfra\Model;

use MailInfra\Internal\Cast;

/**
 * Результат пакетной отправки.
 *
 * Поля results/accepted_count/failed_count повторяют схему BatchSendResponseData
 * из https://docs.mailinfra.ru/openapi/mailinfra-public.json; остальное — метаданные запроса,
 * которых в теле ответа нет. Совпадение состава проверяет SpecParityTest.
 */
final class BatchSendResponseData
{
    /**
     * @param list<BatchSendResult> $results
     * @param bool $replayed true, если это идемпотентный повтор и заново не
     *                       принято ни одно письмо: все принятые были приняты
     *                       прежним запросом с тем же Idempotency-Key.
     * @param string|null $idempotencyKey Ключ, с которым ушёл запрос; с ним
     *                                    повтор батча не продублирует письма.
     * @param string|null $requestId Идентификатор запроса для обращений в поддержку.
     */
    public function __construct(
        public readonly array $results,
        public readonly int $acceptedCount,
        public readonly int $failedCount,
        public readonly bool $replayed = false,
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $requestId = null,
    ) {
    }

    /**
     * Только отклонённые письма — то, ради чего результат батча и разбирают.
     *
     * @return list<BatchSendResult>
     */
    public function failed(): array
    {
        return array_values(array_filter($this->results, static fn (BatchSendResult $item) => !$item->isAccepted()));
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(
        array $data,
        bool $replayed = false,
        ?string $idempotencyKey = null,
        ?string $requestId = null,
    ): self {
        return new self(
            results: array_map(
                static fn (array $item) => BatchSendResult::fromArray($item),
                Cast::objectList($data, 'results'),
            ),
            acceptedCount: Cast::int($data, 'accepted_count'),
            failedCount: Cast::int($data, 'failed_count'),
            replayed: $replayed,
            idempotencyKey: $idempotencyKey,
            requestId: $requestId,
        );
    }
}
