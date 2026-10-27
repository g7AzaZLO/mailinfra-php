<?php

declare(strict_types=1);

namespace MailInfra\Enum;

/**
 * Характер недоставки: постоянная или временная.
 *
 * Значения повторяют перечисление из https://docs.mailinfra.ru/openapi/mailinfra-public.json;
 * совпадение проверяет SpecParityTest.
 */
enum BounceType: string
{
    case Hard = 'hard';
    case Soft = 'soft';
}
