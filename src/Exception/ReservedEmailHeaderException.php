<?php

declare(strict_types=1);

namespace MailInfra\Exception;

/**
 * `422` — попытка переопределить системный заголовок письма.
 */
class ReservedEmailHeaderException extends UnprocessableException
{
}
