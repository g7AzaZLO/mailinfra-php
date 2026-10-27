<?php

declare(strict_types=1);

namespace MailInfra\Exception;

/**
 * `422` — домен не привязан к проекту, которому принадлежит ключ.
 */
class DomainNotAttachedException extends UnprocessableException
{
}
