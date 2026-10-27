<?php

declare(strict_types=1);

namespace MailInfra\Model;

use MailInfra\Exception\MalformedResponseException;
use MailInfra\Internal\Cast;

/**
 * Результат пакетной отправки.
 *
 * Поля повторяют схему BatchSendResponseData из
 * https://docs.mailinfra.ru/openapi/mailinfra-public.json; совпадение состава проверяет SpecParityTest.
 */
final class BatchSendResponseData
{
    /**
     * @param list<BatchSendResult> $results
     */
    public function __construct(
        public readonly array $results,
        public readonly int $acceptedCount,
        public readonly int $failedCount,
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
    public static function fromArray(array $data): self
    {
        $raw = $data['results'] ?? null;
        if (!\is_array($raw)) {
            throw new MalformedResponseException(
                "Ответ API не по контракту: в поле 'results' ожидался список",
                null,
                'malformed_response',
            );
        }

        $results = [];
        foreach ($raw as $item) {
            if (!\is_array($item)) {
                throw new MalformedResponseException(
                    "Ответ API не по контракту: элемент 'results' не объект",
                    null,
                    'malformed_response',
                );
            }
            $results[] = BatchSendResult::fromArray($item);
        }

        return new self(
            results: $results,
            acceptedCount: Cast::int($data, 'accepted_count'),
            failedCount: Cast::int($data, 'failed_count'),
        );
    }
}
