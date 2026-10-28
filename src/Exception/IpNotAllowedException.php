<?php

declare(strict_types=1);

namespace MailInfra\Exception;

/**
 * `403` — API-ключ использован с IP-адреса вне его белого списка.
 *
 * Повтор с того же адреса не поможет: список разрешённых адресов ключа
 * меняется в дашборде.
 */
class IpNotAllowedException extends PermissionDeniedException
{
}
