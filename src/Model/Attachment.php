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
 */
final class Attachment
{
    public const DEFAULT_CONTENT_TYPE = 'application/octet-stream';

    /**
     * @param string $filename Имя файла в письме.
     * @param string $contentBase64 Содержимое файла в base64.
     * @param string $contentType MIME-тип.
     */
    private function __construct(
        public readonly string $filename,
        public readonly string $contentBase64,
        public readonly string $contentType = self::DEFAULT_CONTENT_TYPE,
    ) {
    }

    /**
     * Вложение из сырого содержимого: SDK закодирует его в base64 сам.
     */
    public static function fromContent(
        string $filename,
        string $content,
        string $contentType = self::DEFAULT_CONTENT_TYPE,
    ): self {
        return new self($filename, base64_encode($content), $contentType);
    }

    /**
     * Вложение из файла на диске.
     *
     * @throws InvalidRequestException Если файл не прочитался: молча отправить
     *                                 письмо без обещанного вложения нельзя.
     */
    public static function fromFile(
        string $path,
        ?string $filename = null,
        string $contentType = self::DEFAULT_CONTENT_TYPE,
    ): self {
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new InvalidRequestException(
                "Не удалось прочитать файл вложения: {$path}",
                null,
                'invalid_attachment',
            );
        }

        return self::fromContent($filename ?? basename($path), $content, $contentType);
    }

    /**
     * Вложение из готовой base64-строки.
     */
    public static function fromBase64(
        string $filename,
        string $contentBase64,
        string $contentType = self::DEFAULT_CONTENT_TYPE,
    ): self {
        return new self($filename, $contentBase64, $contentType);
    }

    /**
     * Приводит вложение к виду, который ждёт API.
     *
     * @return array{filename: string, content_base64: string, content_type: string}
     */
    public function toArray(): array
    {
        return [
            'filename' => $this->filename,
            'content_base64' => $this->contentBase64,
            'content_type' => $this->contentType,
        ];
    }
}
