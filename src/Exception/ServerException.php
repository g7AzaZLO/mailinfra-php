<?php

declare(strict_types=1);

namespace MailInfra\Exception;

/**
 * `5xx` — ошибка на стороне MailInfra. Такие запросы SDK ретраит сам.
 */
class ServerException extends MailInfraException
{
}
