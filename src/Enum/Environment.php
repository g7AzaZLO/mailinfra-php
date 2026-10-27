<?php

declare(strict_types=1);

namespace MailInfra\Enum;

/**
 * Окружение API-ключа, распознанное по его префиксу.
 */
enum Environment: string
{
    /** Рабочий ключ mi_live_…: письма уходят получателям. */
    case Live = 'live';

    /**
     * Sandbox-ключ mi_test_…: письмо сохраняется со статусом SIMULATED,
     * не доставляется и не расходует месячный объём плана.
     */
    case Test = 'test';
}
