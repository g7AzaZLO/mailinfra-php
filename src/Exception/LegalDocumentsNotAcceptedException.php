<?php

declare(strict_types=1);

namespace MailInfra\Exception;

/**
 * `422` — не приняты обязательные юридические документы.
 *
 * Политика, оферта или DPA ждут согласия владельца организации, до этого
 * отправка заблокирована. В `getDetails()["pending"]` — список документов,
 * которые нужно принять.
 */
class LegalDocumentsNotAcceptedException extends UnprocessableException
{
}
