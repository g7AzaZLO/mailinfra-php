<?php

declare(strict_types=1);

namespace MailInfra\Exception;

/**
 * `422` — пробное письмо с sandbox-домена (LIVE-ключ) уходит только владельцу ключа.
 */
class SandboxRecipientNotAllowedException extends UnprocessableException
{
}
