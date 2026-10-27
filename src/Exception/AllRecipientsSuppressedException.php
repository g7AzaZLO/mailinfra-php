<?php

declare(strict_types=1);

namespace MailInfra\Exception;

/**
 * `422` — все получатели в списке подавления, отправлять некому.
 */
class AllRecipientsSuppressedException extends UnprocessableException
{
}
