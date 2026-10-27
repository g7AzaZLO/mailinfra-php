<?php

declare(strict_types=1);

namespace MailInfra\Exception;

/**
 * Ответ успешен, но не по контракту: в нём нет поля `data`, которое обещает API.
 */
class MalformedResponseException extends MailInfraException
{
}
