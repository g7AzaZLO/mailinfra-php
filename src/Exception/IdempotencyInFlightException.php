<?php

declare(strict_types=1);

namespace MailInfra\Exception;

/**
 * `409` — запрос с таким `Idempotency-Key` ещё выполняется, повторите позже.
 */
class IdempotencyInFlightException extends ConflictException
{
}
