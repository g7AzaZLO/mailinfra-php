<?php

declare(strict_types=1);

namespace MailInfra\Exception;

/**
 * `401` — API-ключ отсутствует, невалиден или отозван.
 */
class AuthenticationException extends MailInfraException
{
}
