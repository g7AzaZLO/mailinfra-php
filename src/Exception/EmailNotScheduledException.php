<?php

declare(strict_types=1);

namespace MailInfra\Exception;

/**
 * `409` — письмо уже не запланировано: отправлено, отправляется или отменено.
 *
 * Отменить или перенести можно только письмо в статусе `SCHEDULED`. Текущий
 * статус письма — в `getDetails()["status"]`: `CANCELED` после повтора отмены
 * значит, что первая попытка всё-таки дошла.
 */
class EmailNotScheduledException extends ConflictException
{
}
