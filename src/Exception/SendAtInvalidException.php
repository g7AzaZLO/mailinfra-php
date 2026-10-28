<?php

declare(strict_types=1);

namespace MailInfra\Exception;

/**
 * `400` — `send_at` без часового пояса: API не знает, чьё это время.
 *
 * Строку без смещения SDK отбивает ещё до сети (InvalidRequestException), так
 * что это исключение — страховка на случай, когда пояс не распознал уже сервер.
 */
class SendAtInvalidException extends BadRequestException
{
}
