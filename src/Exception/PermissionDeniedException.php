<?php

declare(strict_types=1);

namespace MailInfra\Exception;

/**
 * `403` — у ключа нет прав на операцию.
 *
 * Типичная причина: ключ выпущен с правами `READ_ONLY` (тогда отправка
 * запрещена) или `SENDING_ONLY` (тогда запрещено чтение).
 */
class PermissionDeniedException extends MailInfraException
{
}
