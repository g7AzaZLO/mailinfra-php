<?php

declare(strict_types=1);

namespace MailInfra\Internal;

use MailInfra\Exception\MalformedResponseException;

/**
 * Приведение полей ответа API к типам моделей.
 *
 * Ответ приходит как массив из json_decode, и каждое поле надо проверить:
 * «поле пришло не того типа» должно быть внятной ошибкой SDK, а не
 * TypeError в конструкторе модели где-то в глубине. Nullable-варианты
 * (`nullable*`) отражают поля, которые API честно отдаёт как `null`.
 *
 * @internal
 */
final class Cast
{
    /**
     * @param array<string, mixed> $data
     */
    public static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!\is_string($value)) {
            throw self::malformed($key, 'строку', $value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if ($value === null || \is_string($value)) {
            return $value;
        }

        throw self::malformed($key, 'строку или null', $value);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!\is_int($value)) {
            throw self::malformed($key, 'целое число', $value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function nullableInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;
        if ($value === null || \is_int($value)) {
            return $value;
        }

        throw self::malformed($key, 'целое число или null', $value);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function nullableBool(array $data, string $key): ?bool
    {
        $value = $data[$key] ?? null;
        if ($value === null || \is_bool($value)) {
            return $value;
        }

        throw self::malformed($key, 'булево значение или null', $value);
    }

    /**
     * Список строк; отсутствующее поле — пустой список.
     *
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    public static function stringList(array $data, string $key): array
    {
        // `??` срабатывает и на отсутствующем ключе, и на явном null, поэтому
        // отдельная проверка на null ниже была бы мёртвым кодом.
        $value = $data[$key] ?? [];
        if (!\is_array($value)) {
            throw self::malformed($key, 'список строк', $value);
        }

        $result = [];
        foreach ($value as $item) {
            if (!\is_string($item)) {
                throw self::malformed($key, 'список строк', $value);
            }
            $result[] = $item;
        }

        return $result;
    }

    /**
     * Список строк, который API отдаёт как `null` для писем без этого поля.
     *
     * @param array<string, mixed> $data
     *
     * @return list<string>|null
     */
    public static function nullableStringList(array $data, string $key): ?array
    {
        if (($data[$key] ?? null) === null) {
            return null;
        }

        return self::stringList($data, $key);
    }

    /**
     * Карта «строка → строка»: заголовки письма.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, string>
     */
    public static function stringMap(array $data, string $key): array
    {
        // `??` срабатывает и на отсутствующем ключе, и на явном null, поэтому
        // отдельная проверка на null ниже была бы мёртвым кодом.
        $value = $data[$key] ?? [];
        if (!\is_array($value)) {
            throw self::malformed($key, 'объект со строковыми значениями', $value);
        }

        $result = [];
        foreach ($value as $name => $item) {
            if (!\is_string($item)) {
                throw self::malformed($key, 'объект со строковыми значениями', $value);
            }
            $result[(string) $name] = $item;
        }

        return $result;
    }

    /**
     * Произвольный объект: полезная нагрузка события, детали ошибки.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function map(array $data, string $key): array
    {
        // `??` срабатывает и на отсутствующем ключе, и на явном null, поэтому
        // отдельная проверка на null ниже была бы мёртвым кодом.
        $value = $data[$key] ?? [];
        if (!\is_array($value)) {
            throw self::malformed($key, 'объект', $value);
        }

        return $value;
    }

    /**
     * Список объектов: отброшенные получатели с причиной в значении.
     *
     * @param array<string, mixed> $data
     *
     * @return list<array<string, string>>
     */
    public static function stringMapList(array $data, string $key): array
    {
        // `??` срабатывает и на отсутствующем ключе, и на явном null, поэтому
        // отдельная проверка на null ниже была бы мёртвым кодом.
        $value = $data[$key] ?? [];
        if (!\is_array($value)) {
            throw self::malformed($key, 'список объектов', $value);
        }

        $result = [];
        foreach ($value as $item) {
            if (!\is_array($item)) {
                throw self::malformed($key, 'список объектов', $value);
            }
            $entry = [];
            foreach ($item as $name => $inner) {
                if (\is_string($inner)) {
                    $entry[(string) $name] = $inner;
                    continue;
                }
                // По спеке значения здесь строковые. Если пришло что-то ещё —
                // показываем его как JSON, а не роняем разбор всего ответа:
                // это диагностическая причина пропуска получателя.
                $encoded = json_encode($inner, \JSON_UNESCAPED_UNICODE);
                $entry[(string) $name] = $encoded === false ? '' : $encoded;
            }
            $result[] = $entry;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function dateTime(array $data, string $key): \DateTimeImmutable
    {
        $value = self::nullableDateTime($data, $key);
        if ($value === null) {
            throw self::malformed($key, 'дату и время', $data[$key] ?? null);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function nullableDateTime(array $data, string $key): ?\DateTimeImmutable
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!\is_string($value)) {
            throw self::malformed($key, 'дату и время в ISO-8601', $value);
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $exception) {
            throw new MalformedResponseException(
                "Поле '{$key}' пришло с неразбираемой датой: {$value}",
                null,
                'malformed_response',
                [],
                null,
                $exception,
            );
        }
    }

    /**
     * Значение перечисления.
     *
     * @template T of \BackedEnum
     *
     * @param array<string, mixed> $data
     * @param class-string<T> $enum
     *
     * @return T
     */
    public static function enum(array $data, string $key, string $enum): \BackedEnum
    {
        $value = self::nullableEnum($data, $key, $enum);
        if ($value === null) {
            throw self::malformed($key, "значение {$enum}", $data[$key] ?? null);
        }

        return $value;
    }

    /**
     * Значение перечисления, которого может не быть.
     *
     * Неизвестное значение — это не повод ронять разбор ответа: бэкенд может
     * завести новую категорию отказа раньше, чем обновится SDK. Такое поле
     * приходит как null, а не как исключение.
     *
     * @template T of \BackedEnum
     *
     * @param array<string, mixed> $data
     * @param class-string<T> $enum
     *
     * @return T|null
     */
    public static function nullableEnum(array $data, string $key, string $enum): ?\BackedEnum
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!\is_string($value)) {
            throw self::malformed($key, "значение {$enum}", $value);
        }

        return $enum::tryFrom($value);
    }

    private static function malformed(string $key, string $expected, mixed $actual): MalformedResponseException
    {
        $type = get_debug_type($actual);

        return new MalformedResponseException(
            "Ответ API не по контракту: в поле '{$key}' ожидалось {$expected}, пришло {$type}",
            null,
            'malformed_response',
        );
    }
}
