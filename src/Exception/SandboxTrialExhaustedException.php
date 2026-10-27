<?php

declare(strict_types=1);

namespace MailInfra\Exception;

/**
 * `403` — пробная sandbox-отправка уже израсходована; нужен свой домен.
 */
class SandboxTrialExhaustedException extends PermissionDeniedException
{
}
