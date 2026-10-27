<?php

declare(strict_types=1);

namespace MailInfra\Exception;

/**
 * `422` — в `variables` не хватает переменной, которую требует шаблон.
 */
class TemplateVariableMissingException extends UnprocessableException
{
}
