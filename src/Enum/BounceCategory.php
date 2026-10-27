<?php

declare(strict_types=1);

namespace MailInfra\Enum;

/**
 * Причина недоставки.
 *
 * Значения повторяют перечисление из https://docs.mailinfra.ru/openapi/mailinfra-public.json;
 * совпадение проверяет SpecParityTest.
 */
enum BounceCategory: string
{
    case InvalidRecipient = 'invalid-recipient';
    case MailboxFull = 'mailbox-full';
    case MessageTooLarge = 'message-too-large';
    case ContentSpamBlock = 'content-spam-block';
    case PolicyRejection = 'policy-rejection';
    case ReputationBlocklist = 'reputation-blocklist';
    case Greylisting = 'greylisting';
    case DnsMxFailure = 'dns-mx-failure';
    case ConnectionError = 'connection-error';
    case RateLimited = 'rate-limited';
    case AuthFailure = 'auth-failure';
    case Unknown = 'unknown';
}
