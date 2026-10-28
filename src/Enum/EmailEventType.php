<?php

declare(strict_types=1);

namespace MailInfra\Enum;

/**
 * Тип события жизненного цикла письма.
 *
 * Значения повторяют перечисление из https://docs.mailinfra.ru/openapi/mailinfra-public.json;
 * совпадение проверяет SpecParityTest.
 */
enum EmailEventType: string
{
    /** Письмо запланировано или перенесено (`send_at`). */
    case Scheduled = 'scheduled';
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case SmtpConnected = 'smtp_connected';
    case Opened = 'opened';
    case Clicked = 'clicked';
    case Bounced = 'bounced';
    case Complained = 'complained';
    case Failed = 'failed';
    case Simulated = 'simulated';
    case Deferred = 'deferred';
    /** Запланированное письмо отменено. */
    case Canceled = 'canceled';
}
