<?php

declare(strict_types=1);

namespace MailInfra\Enum;

/**
 * Что известно про DKIM-подпись письма.
 *
 * Значения повторяют перечисление из https://docs.mailinfra.ru/openapi/mailinfra-public.json;
 * совпадение проверяет SpecParityTest.
 */
enum DkimSigningStatus: string
{
    case Expected = 'expected';
    case Confirmed = 'confirmed';
    case Failed = 'failed';
    case NotSigned = 'not_signed';
}
