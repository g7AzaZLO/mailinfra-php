<?php

declare(strict_types=1);

namespace MailInfra\Enum;

/**
 * Транспорт, через который письмо попало в отправку.
 *
 * Значения повторяют перечисление из https://docs.mailinfra.ru/openapi/mailinfra-public.json;
 * совпадение проверяет SpecParityTest.
 */
enum EmailSource: string
{
    case Api = 'API';
    case Smtp = 'SMTP';
    case Campaign = 'CAMPAIGN';
}
