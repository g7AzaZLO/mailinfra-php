<?php

declare(strict_types=1);

namespace MailInfra\Model;

use MailInfra\Exception\InvalidRequestException;

/**
 * Вложение письма.
 *
 * В API вложение уходит base64-строкой, но вызывающему удобнее отдать сырое
 * содержимое файла — тогда кодирует SDK. Если base64 уже готов, используйте
 * `Attachment::fromBase64()`.
 *
 * Вложение с `contentId` — inline-картинка: в HTML на неё ссылаются как
 * `<img src="cid:logo">`, и в письмо она встраивается, а не прикладывается
 * файлом.
 */
final class Attachment
{
    public const DEFAULT_CONTENT_TYPE = 'application/octet-stream';

    /**
     * @param string $filename Имя файла в письме.
     * @param string $contentBase64 Содержимое файла в base64.
     * @param string $contentType MIME-тип.
     * @param string|null $contentId Content-ID inline-картинки (без `cid:`);
     *                               null — обычное вложение файлом.
     */
    private function __construct(
        public readonly string $filename,
        public readonly string $contentBase64,
        public readonly string $contentType = self::DEFAULT_CONTENT_TYPE,
        public readonly ?string $contentId = null,
    ) {
    }

    /**
     * Вложение из сырого содержимого: SDK закодирует его в base64 сам.
     *
     * @param string|null $contentId Content-ID inline-картинки; null — обычное вложение.
     */
    public static function fromContent(
        string $filename,
        string $content,
        string $contentType = self::DEFAULT_CONTENT_TYPE,
        ?string $contentId = null,
    ): self {
        return new self($filename, base64_encode($content), $contentType, $contentId);
    }

    /**
     * Вложение из файла на диске.
     *
     * @param string|null $contentId Content-ID inline-картинки; null — обычное вложение.
     *
     * @throws InvalidRequestException Если файл не прочитался: молча отправить
     *                                 письмо без обещанного вложения нельзя.
     */
    public static function fromFile(
        string $path,
        ?string $filename = null,
        string $contentType = self::DEFAULT_CONTENT_TYPE,
        ?string $contentId = null,
    ): self {
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new InvalidRequestException(
                "Не удалось прочитать файл вложения: {$path}",
                null,
                'invalid_attachment',
            );
        }

        return self::fromContent($filename ?? basename($path), $content, $contentType, $contentId);
    }

    /**
     * Вложение из готовой base64-строки.
     *
     * @param string|null $contentId Content-ID inline-картинки; null — обычное вложение.
     */
    public static function fromBase64(
        string $filename,
        string $contentBase64,
        string $contentType = self::DEFAULT_CONTENT_TYPE,
        ?string $contentId = null,
    ): self {
        return new self($filename, $contentBase64, $contentType, $contentId);
    }

    /**
     * Приводит вложение к виду, который ждёт API.
     *
     * @return array{filename: string, content_base64: string, content_type: string, content_id?: string}
     */
    public function toArray(): array
    {
        $payload = [
            'filename' => $this->filename,
            'content_base64' => $this->contentBase64,
            'content_type' => $this->contentType,
        ];
        if ($this->contentId !== null) {
            $payload['content_id'] = $this->contentId;
        }

        return $payload;
    }
}
