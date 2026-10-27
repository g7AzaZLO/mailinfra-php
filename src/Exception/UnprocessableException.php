<?php

declare(strict_types=1);

namespace MailInfra\Exception;

/**
 * `422` — не прошла валидация полей.
 */
class UnprocessableException extends MailInfraException
{
    /**
     * Пер-полевые причины отказа из `details.fields`.
     *
     * Бэкенд кладёт их при коде `request_validation_failed`. Записи не по
     * контракту отбрасываются: обработчик ошибки не должен падать из-за
     * неожиданной формы деталей.
     *
     * @return list<array{field: string, message: string, code?: string}>
     */
    public function getFields(): array
    {
        $raw = $this->getDetails()['fields'] ?? null;
        if (!\is_array($raw)) {
            return [];
        }

        $fields = [];
        foreach ($raw as $item) {
            if (!\is_array($item) || !\is_string($item['field'] ?? null) || !\is_string($item['message'] ?? null)) {
                continue;
            }
            $fields[] = $item;
        }

        return $fields;
    }
}
