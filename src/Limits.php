<?php

declare(strict_types=1);

namespace MailInfra;

/**
 * Ограничения запроса, зафиксированные в https://docs.mailinfra.ru/openapi/mailinfra-public.json.
 *
 * Проверяем их на клиенте, чтобы не тратить запрос: API вернул бы 422, но
 * письмо с вложениями уезжает целиком (до ~25 МиБ) только чтобы вернуться с
 * ошибкой. Совпадение констант со спекой проверяет SpecParityTest. Часть
 * лимитов `metadata` спека называет только в описании поля, а длину ключа и
 * размер в байтах — нигде: их источник — app.services.limits бэкенда.
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

    /** Потолок длины Content-ID inline-вложения, символов: 255 и внешние `<>`. */
    public const MAX_CONTENT_ID_LENGTH = 257;

    /** Потолок числа ключей в `metadata`. */
    public const MAX_METADATA_KEYS = 20;

    /** Потолок длины ключа `metadata`, символов: латиница, цифры, `_`, `-` и `.`. */
    public const MAX_METADATA_KEY_LENGTH = 40;

    /** Потолок длины значения `metadata`, символов. */
    public const MAX_METADATA_VALUE_LENGTH = 500;

    /** Потолок размера всего `metadata` в JSON, байт UTF-8. */
    public const MAX_METADATA_BYTES = 4096;

    /** Нижняя граница `ttl`, секунды: минута — не меньше одного цикла выпуска и повтора. */
    public const MIN_TTL_SECONDS = 60;

    /** Верхняя граница `ttl`, секунды: сутки — дольше MTA письмо всё равно не повторяет. */
    public const MAX_TTL_SECONDS = 86400;

    /** Потолок числа писем в одном батче. */
    public const MAX_BATCH_SIZE = 100;

    /** Потолок длины `Idempotency-Key`: бэкенд отклоняет длиннее с 422. */
    public const IDEMPOTENCY_KEY_MAX_LENGTH = 255;

    /**
     * Потолок длины `Idempotency-Key` батча. Короче, чем у одиночной отправки:
     * бэкенд дописывает к ключу разделитель и номер письма, и ключ каждого
     * письма обязан уложиться в общий потолок.
     */
    public const BATCH_IDEMPOTENCY_KEY_MAX_LENGTH = 252;

    private function __construct()
    {
    }
}
