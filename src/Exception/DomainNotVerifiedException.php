<?php

declare(strict_types=1);

namespace MailInfra\Exception;

/**
 * `422` — домен отправителя ещё не подтверждён (DNS-записи не сошлись).
 */
class DomainNotVerifiedException extends UnprocessableException
{
}
