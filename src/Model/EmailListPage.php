<?php

declare(strict_types=1);

namespace MailInfra\Model;

use MailInfra\Internal\Cast;

/**
 * Страница списка писем: письма, признак следующей страницы и её курсор.
 *
 * Поля повторяют схему EmailListResponse из https://docs.mailinfra.ru/openapi/mailinfra-public.json;
 * совпадение состава проверяет SpecParityTest.
 *
 * Страницу можно обойти `foreach` и посчитать `count()` — так продолжает
 * работать код, написанный под 0.1.0, когда `list()` возвращал массив. Но это
 * только одна страница: все письма по фильтру, без ручной работы с курсором,
 * отдаёт `Emails::listAll()`.
 *
 * @implements \IteratorAggregate<int, EmailListItem>
 */
final class EmailListPage implements \IteratorAggregate, \Countable
{
    /**
     * @param list<EmailListItem> $data Письма страницы, новые сначала.
     * @param bool $hasMore Есть ли письма после этой страницы.
     * @param string|null $nextCursor Курсор следующей страницы для
     *                                `list(cursor: …)`; null на последней.
     */
    public function __construct(
        public readonly array $data,
        public readonly bool $hasMore,
        public readonly ?string $nextCursor,
    ) {
    }

    /**
     * @return \ArrayIterator<int, EmailListItem>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->data);
    }

    /**
     * Сколько писем на этой странице — не во всей выборке.
     */
    public function count(): int
    {
        return \count($this->data);
    }

    /**
     * @param array<string, mixed> $body Весь объект ответа: `has_more` и
     *                                   `next_cursor` лежат рядом с `data`,
     *                                   а не внутри него.
     */
    public static function fromArray(array $body): self
    {
        return new self(
            data: array_map(
                static fn (array $item) => EmailListItem::fromArray($item),
                Cast::objectList($body, 'data'),
            ),
            hasMore: Cast::bool($body, 'has_more'),
            nextCursor: Cast::nullableString($body, 'next_cursor'),
        );
    }
}
