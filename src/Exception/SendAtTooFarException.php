<?php

declare(strict_types=1);

namespace MailInfra\Exception;

/**
 * `400` — `send_at` дальше горизонта: письмо планируется не больше чем на
 * 7 дней от приёма (`getDetails()["max_days"]`).
 */
class SendAtTooFarException extends BadRequestException
{
}
