<?php

declare(strict_types=1);

namespace MailInfra\Tests;

/**
 * Общие для всех SDK файлы: OpenAPI-спека и вектора подписи вебхуков.
 *
 * В публичном репозитории SDK они лежат в `resources/`, в монорепозитории
 * MailInfra — в соседних каталогах `sdk/openapi/` и `sdk/testdata/`.
 */
final class SharedResources
{
    /**
     * Путь к общему файлу в том раскладе, в котором запущены тесты.
     *
     * @param string $name Имя файла.
     * @param string $monorepoDir Каталог файла внутри `sdk/` монорепозитория.
     */
    public static function path(string $name, string $monorepoDir): string
    {
        $mirror = __DIR__ . '/../resources/' . $name;

        return is_file($mirror) ? $mirror : __DIR__ . '/../../' . $monorepoDir . '/' . $name;
    }
}
