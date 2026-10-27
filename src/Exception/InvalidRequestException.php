<?php

declare(strict_types=1);

namespace MailInfra\Exception;

/**
 * Параметры вызова не проходят ограничения API — запрос до сети не дошёл.
 *
 * Лимиты получателей, тегов, темы и вложений проверяются на клиенте: письмо с
 * вложениями весит до ~25 МиБ, и уезжать целиком ради 422 оно не должно.
 */
class InvalidRequestException extends MailInfraException
{
}
