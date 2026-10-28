<?php

declare(strict_types=1);

namespace MailInfra\Enum;

/**
 * Статус письма.
 *
 * Значения повторяют перечисление из https://docs.mailinfra.ru/openapi/mailinfra-public.json;
 * совпадение проверяет SpecParityTest.
 */
enum EmailStatus: string
{
    /** Отложенная отправка (`send_at`): ждёт своего времени, его можно отменить или перенести. */
    case Scheduled = 'SCHEDULED';
    case Queued = 'QUEUED';
    case Sending = 'SENDING';
    case Sent = 'SENT';
    case Delivered = 'DELIVERED';
    case Bounced = 'BOUNCED';
    case Complained = 'COMPLAINED';
    case Failed = 'FAILED';
    case Simulated = 'SIMULATED';
    /** Запланированное письмо отменено до отправки. */
    case Canceled = 'CANCELED';
}
