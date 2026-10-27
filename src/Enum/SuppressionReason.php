<?php

declare(strict_types=1);

namespace MailInfra\Enum;

/**
 * Причина попадания адреса в список подавления.
 *
 * Значения повторяют перечисление из https://docs.mailinfra.ru/openapi/mailinfra-public.json;
 * совпадение проверяет SpecParityTest.
 */
enum SuppressionReason: string
{
    case HardBounce = 'HARD_BOUNCE';
    case Complaint = 'COMPLAINT';
    case Unsubscribe = 'UNSUBSCRIBE';
    case Manual = 'MANUAL';
}
