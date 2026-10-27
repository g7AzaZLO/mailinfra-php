<?php

declare(strict_types=1);

namespace MailInfra;

/**
 * Ограничения запроса, зафиксированные в https://docs.mailinfra.ru/openapi/mailinfra-public.json.
 *
 * Проверяем их на клиенте, чтобы не тратить запрос: API вернул бы 422, но
 * письмо с вложениями уезжает целиком (до ~25 МиБ) только чтобы вернуться с
 * ошибкой. Совпадение констант со спекой проверяет SpecParityTest.
 */
final class Limits
{
    /** Потолок адресов в каждом из полей `to`, `cc` и `bcc`. */
    public const MAX_RECIPIENTS = 50;

    /** Потолок адресов в `reply_to`. */
    public const MAX_REPLY_TO = 10;

    /** Потолок тегов письма. */
    public const MAX_TAGS = 10;

    /** Потолок длины темы, символов. */
    public const MAX_SUBJECT_LENGTH = 998;

    /** Потолок длины отображаемого имени отправителя, символов. */
    public const MAX_FROM_NAME_LENGTH = 200;

    /** Потолок длины слага шаблона, символов. */
    public const MAX_TEMPLATE_LENGTH = 100;

    /** Потолок числа вложений в письме. */
    public const MAX_ATTACHMENTS = 10;

    /** Потолок длины base64-содержимого вложения. */
    public const MAX_ATTACHMENT_BASE64_LENGTH = 14348938;

    /** Потолок длины имени файла вложения, символов. */
    public const MAX_FILENAME_LENGTH = 255;

    /** Потолок длины MIME-типа вложения, символов. */
    public const MAX_CONTENT_TYPE_LENGTH = 255;

    /** Потолок числа писем в одном батче. */
    public const MAX_BATCH_SIZE = 100;

    /** Потолок длины `Idempotency-Key`: бэкенд отклоняет длиннее с 422. */
    public const IDEMPOTENCY_KEY_MAX_LENGTH = 255;

    private function __construct()
    {
    }
}
