<?php

/**
 * Ресурс писем: отправка, батч, чтение статусов и событий.
 *
 * Об идемпотентности. `POST /emails` принимает `Idempotency-Key`, и SDK ставит
 * его сам: ключ генерируется один раз на вызов и переиспользуется всеми
 * повторами внутри этого вызова. Поэтому сетевой сбой или `503` не
 * превращаются в два письма. Повторный вызов `send()` — это новый ключ и новое
 * письмо, как и ожидает вызывающий. Если ключа нет
 * (`withoutIdempotencyKey: true`, `autoIdempotency: false`), запрос помечается
 * неидемпотентным: дедуплицировать повтор бэкенду нечем.
 *
 * `POST /emails/batch` заголовок не принимает вовсе (бэкенд коммитит письма
 * батча по одному), поэтому он неидемпотентен всегда и повторяется только на
 * `429` — единственном отказе, который случается до приёма писем.
 */

declare(strict_types=1);

namespace MailInfra\Resource;

use MailInfra\Enum\EmailStatus;
use MailInfra\Exception\InvalidRequestException;
use MailInfra\Limits;
use MailInfra\MailInfra;
use MailInfra\Model\Attachment;
use MailInfra\Model\BatchSendResponseData;
use MailInfra\Model\EmailDetail;
use MailInfra\Model\EmailEvent;
use MailInfra\Model\EmailListItem;
use MailInfra\Model\SendResult;

/**
 * Письма проекта. Доступен как `$client->emails`.
 */
final class Emails
{
    private const EMAILS_PATH = '/emails';
    private const BATCH_PATH = '/emails/batch';

    private const IDEMPOTENCY_KEY_HEADER = 'Idempotency-Key';
    private const IDEMPOTENT_REPLAYED_HEADER = 'Idempotent-Replayed';

    /**
     * Ключи, которые принимает элемент батча — те же, что у send(), кроме
     * относящихся к идемпотентности.
     *
     * @var list<string>
     */
    private const BATCH_ITEM_KEYS = [
        'from', 'to', 'subject', 'html', 'text', 'fromName',
        'cc', 'bcc', 'replyTo', 'headers', 'tags', 'template', 'variables', 'attachments',
    ];

    public function __construct(private readonly MailInfra $client)
    {
    }

    /**
     * Отправляет письмо.
     *
     * @param string $from Адрес отправителя на подтверждённом домене.
     * @param string|list<string> $to Получатель или получатели.
     * @param string|null $subject Тема; обязательна без шаблона.
     * @param string|null $html HTML-часть.
     * @param string|null $text Текстовая часть.
     * @param string|null $fromName Отображаемое имя отправителя.
     * @param string|list<string>|null $cc Копия.
     * @param string|list<string>|null $bcc Скрытая копия.
     * @param string|list<string>|null $replyTo Адреса для ответа.
     * @param array<string, string>|null $headers Дополнительные заголовки письма.
     * @param list<string>|null $tags Теги.
     * @param string|null $template Слаг шаблона вместо html/text.
     * @param array<string, mixed>|null $variables Переменные шаблона.
     * @param list<Attachment>|null $attachments Вложения.
     * @param string|null $idempotencyKey Свой ключ идемпотентности; по умолчанию
     *                                    SDK генерирует uuid4 на каждый вызов.
     * @param bool $withoutIdempotencyKey Отправить вообще без ключа. Осознанный
     *                                    отказ от защиты: бэкенду будет нечем
     *                                    распознать повтор, и SDK перестанет
     *                                    ретраить запрос после 5xx и таймаутов.
     *
     * @throws InvalidRequestException Параметры не проходят ограничения API.
     * @throws \MailInfra\Exception\MailInfraException Ошибка API или транспорта.
     */
    public function send(
        string $from,
        string|array $to,
        ?string $subject = null,
        ?string $html = null,
        ?string $text = null,
        ?string $fromName = null,
        string|array|null $cc = null,
        string|array|null $bcc = null,
        string|array|null $replyTo = null,
        ?array $headers = null,
        ?array $tags = null,
        ?string $template = null,
        ?array $variables = null,
        ?array $attachments = null,
        ?string $idempotencyKey = null,
        bool $withoutIdempotencyKey = false,
    ): SendResult {
        $payload = self::buildPayload([
            'from' => $from,
            'to' => $to,
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
            'fromName' => $fromName,
            'cc' => $cc,
            'bcc' => $bcc,
            'replyTo' => $replyTo,
            'headers' => $headers,
            'tags' => $tags,
            'template' => $template,
            'variables' => $variables,
            'attachments' => $attachments,
        ]);

        $key = $this->resolveIdempotencyKey($idempotencyKey, $withoutIdempotencyKey);

        $response = $this->client->request(
            method: 'POST',
            path: self::EMAILS_PATH,
            body: $payload,
            headers: $key === null ? [] : [self::IDEMPOTENCY_KEY_HEADER => $key],
            // Без Idempotency-Key бэкенду нечем распознать повтор
            // (email_service.send_email дедуплицирует только по ключу), поэтому
            // такой запрос неидемпотентен: повтор после 5xx создал бы второе письмо.
            idempotent: $key !== null,
        );

        // 200 вместо 202 — идемпотентный повтор; заголовок дублирует признак
        // для прокси, которые статус переписывают.
        $replayed = $response->statusCode === 200
            || strtolower((string) $response->header(self::IDEMPOTENT_REPLAYED_HEADER)) === 'true';

        return SendResult::fromArray($response->arrayData(), $replayed, $key, $response->requestId);
    }

    /**
     * Отправляет до 100 писем одним запросом.
     *
     * Батч возвращает `202`, даже если часть писем не принята: проверяйте
     * `$result->error` у каждого элемента `results`, а не только отсутствие
     * исключения. `Idempotency-Key` этот эндпоинт не поддерживает, поэтому SDK
     * не повторяет его после `5xx`, таймаута и обрыва соединения — повтор мог
     * бы отправить часть писем дважды. Повторяется только `429`.
     *
     * @param list<array<string, mixed>> $emails Наборы параметров, как у send().
     *
     * @throws InvalidRequestException Пустой или слишком большой батч, либо
     *                                 элемент с неизвестным параметром.
     */
    public function sendBatch(array $emails): BatchSendResponseData
    {
        if ($emails === []) {
            throw new InvalidRequestException('Пустой батч: передайте хотя бы одно письмо', null, 'empty_batch');
        }
        if (\count($emails) > Limits::MAX_BATCH_SIZE) {
            throw new InvalidRequestException(
                \sprintf(
                    'В батче %d писем, а API принимает не больше %d за раз',
                    \count($emails),
                    Limits::MAX_BATCH_SIZE,
                ),
                null,
                'batch_too_large',
            );
        }

        $payloads = [];
        foreach (array_values($emails) as $index => $item) {
            // Эндпоинт батча заголовок не принимает вовсе, и тихо выбросить
            // ключ нельзя: его добавляют ровно для защиты от дублей.
            foreach (array_keys($item) as $key) {
                if (\in_array($key, ['idempotencyKey', 'withoutIdempotencyKey'], true)) {
                    throw new InvalidRequestException(
                        "Письмо #{$index} в батче задаёт {$key}, а POST /emails/batch этот заголовок не "
                        . 'принимает — бэкенд коммитит письма батча по одному. Отправляйте такие письма через send()',
                        null,
                        'invalid_batch_item',
                    );
                }
                if (!\in_array($key, self::BATCH_ITEM_KEYS, true)) {
                    throw new InvalidRequestException(
                        "Письмо #{$index} в батче содержит неизвестный параметр '{$key}'",
                        null,
                        'invalid_batch_item',
                    );
                }
            }
            $payloads[] = self::buildPayload($item);
        }

        $response = $this->client->request(
            method: 'POST',
            path: self::BATCH_PATH,
            body: ['emails' => $payloads],
            idempotent: false,
        );

        return BatchSendResponseData::fromArray($response->arrayData());
    }

    /**
     * Возвращает письмо с телом, заголовками и диагностикой доставки.
     *
     * @throws \MailInfra\Exception\NotFoundException Письма нет или оно принадлежит другому проекту.
     */
    public function get(string $emailId): EmailDetail
    {
        $response = $this->client->request('GET', self::emailPath($emailId));

        return EmailDetail::fromArray($response->arrayData());
    }

    /**
     * Возвращает письма проекта по фильтрам.
     *
     * @param \DateTimeInterface|string|null $from Начало интервала по времени создания.
     * @param \DateTimeInterface|string|null $to Конец интервала.
     * @param int|null $limit Размер выборки, 1..100 (по умолчанию 25 на стороне API).
     *
     * @return list<EmailListItem>
     */
    public function list(
        ?EmailStatus $status = null,
        ?string $fromEmail = null,
        ?string $toEmail = null,
        ?string $tag = null,
        \DateTimeInterface|string|null $from = null,
        \DateTimeInterface|string|null $to = null,
        ?int $limit = null,
    ): array {
        $query = array_filter([
            'status' => $status?->value,
            'from_email' => $fromEmail,
            'to_email' => $toEmail,
            'tag' => $tag,
            'from' => self::moment($from),
            'to' => self::moment($to),
            'limit' => $limit,
        ], static fn ($value) => $value !== null);

        $response = $this->client->request('GET', self::EMAILS_PATH, query: $query);

        return array_map(
            static fn (array $item) => EmailListItem::fromArray($item),
            self::itemList($response->data),
        );
    }

    /**
     * Возвращает события письма в хронологическом порядке.
     *
     * @return list<EmailEvent>
     */
    public function events(string $emailId): array
    {
        $response = $this->client->request('GET', self::emailPath($emailId, '/events'));

        return array_map(
            static fn (array $item) => EmailEvent::fromArray($item),
            self::itemList($response->data),
        );
    }

    /**
     * Решает, с каким ключом уйдёт отправка.
     */
    private function resolveIdempotencyKey(?string $provided, bool $without): ?string
    {
        if ($without) {
            if ($provided !== null) {
                throw new InvalidRequestException(
                    'Одновременно заданы idempotencyKey и withoutIdempotencyKey — непонятно, '
                    . 'отправлять с ключом или без',
                    null,
                    'invalid_idempotency_key',
                );
            }

            return null;
        }

        if ($provided === null) {
            return $this->client->autoIdempotency ? self::uuid4() : null;
        }

        // Пустой ключ бэкенд не видит: дедупликация в email_service.send_email
        // стоит под `if payload.idempotency_key:`, и пустая строка туда не
        // проходит. Молча отправить такой запрос нельзя — он помечается
        // идемпотентным, повторяется после 5xx и создаёт второе письмо.
        if (trim($provided) === '') {
            throw new InvalidRequestException(
                'Пустой Idempotency-Key: бэкенд его игнорирует, а повтор после 5xx создал бы второе '
                . 'письмо. Передайте непустой ключ или withoutIdempotencyKey: true',
                null,
                'invalid_idempotency_key',
            );
        }
        // Значение заголовка уходит байтами, а бэкенд читает их как latin-1:
        // 255 кириллических символов приезжают к нему как 510 и получают 422.
        // Управляющие символы отбиваем заодно: CR и LF в значении заголовка —
        // это инъекция заголовков.
        if (preg_match('/^[\x20-\x7e]*$/', $provided) !== 1) {
            throw new InvalidRequestException(
                'Idempotency-Key должен состоять из печатаемых ASCII-символов: значение заголовка '
                . 'уходит байтами, и бэкенд читает его как latin-1 — кириллица и эмодзи до него не доедут',
                null,
                'invalid_idempotency_key',
            );
        }
        if (\strlen($provided) > Limits::IDEMPOTENCY_KEY_MAX_LENGTH) {
            throw new InvalidRequestException(
                \sprintf(
                    'Idempotency-Key длиннее %d символов — API отклонит такой запрос с 422',
                    Limits::IDEMPOTENCY_KEY_MAX_LENGTH,
                ),
                null,
                'invalid_idempotency_key',
            );
        }

        return $provided;
    }

    /**
     * Собирает и проверяет тело запроса на отправку.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private static function buildPayload(array $params): array
    {
        $from = $params['from'] ?? null;
        if (!\is_string($from) || $from === '') {
            throw new InvalidRequestException(
                'Не задан from: адрес отправителя обязателен',
                null,
                'invalid_request',
            );
        }

        $to = self::asList($params['to'] ?? null);
        if ($to === null || $to === []) {
            throw new InvalidRequestException('Не задан to: нужен хотя бы один получатель', null, 'invalid_request');
        }

        $cc = self::asList($params['cc'] ?? null);
        $bcc = self::asList($params['bcc'] ?? null);
        $replyTo = self::asList($params['replyTo'] ?? null);
        $tags = $params['tags'] ?? null;
        $attachments = $params['attachments'] ?? null;

        self::checkCount('to', \count($to), Limits::MAX_RECIPIENTS);
        self::checkCount('cc', \count($cc ?? []), Limits::MAX_RECIPIENTS);
        self::checkCount('bcc', \count($bcc ?? []), Limits::MAX_RECIPIENTS);
        self::checkCount('replyTo', \count($replyTo ?? []), Limits::MAX_REPLY_TO);
        self::checkCount('tags', \count((array) ($tags ?? [])), Limits::MAX_TAGS);
        self::checkCount('attachments', \count((array) ($attachments ?? [])), Limits::MAX_ATTACHMENTS);

        // Длину считаем в символах, а не в байтах: лимит в спеке — символьный,
        // и strlen отбраковал бы кириллическую тему вдвое раньше срока.
        self::checkLength('subject', $params['subject'] ?? null, Limits::MAX_SUBJECT_LENGTH);
        self::checkLength('fromName', $params['fromName'] ?? null, Limits::MAX_FROM_NAME_LENGTH);
        self::checkLength('template', $params['template'] ?? null, Limits::MAX_TEMPLATE_LENGTH);

        $payload = [
            'from' => $from,
            'from_name' => $params['fromName'] ?? null,
            'to' => $to,
            'cc' => $cc,
            'bcc' => $bcc,
            'reply_to' => $replyTo,
            'subject' => $params['subject'] ?? null,
            'html' => $params['html'] ?? null,
            'text' => $params['text'] ?? null,
            'headers' => $params['headers'] ?? null,
            'tags' => $tags,
            'template' => $params['template'] ?? null,
            'variables' => $params['variables'] ?? null,
            'attachments' => self::attachmentsPayload($attachments),
        ];

        // Пустые значения не отправляем: `"html": null` бэкенд принял бы, но
        // шум в логах ни к чему.
        return array_filter($payload, static fn ($value) => $value !== null && $value !== []);
    }

    /**
     * Приводит вложения к виду, который ждёт API.
     *
     * @param mixed $attachments
     *
     * @return list<array<string, string>>|null
     */
    private static function attachmentsPayload(mixed $attachments): ?array
    {
        if ($attachments === null || $attachments === []) {
            return null;
        }
        if (!\is_array($attachments)) {
            throw new InvalidRequestException(
                'attachments должен быть списком MailInfra\\Model\\Attachment',
                null,
                'invalid_attachment',
            );
        }

        $payload = [];
        foreach (array_values($attachments) as $index => $attachment) {
            if (!$attachment instanceof Attachment) {
                throw new InvalidRequestException(
                    "Вложение #{$index} не является MailInfra\\Model\\Attachment — соберите его через "
                    . 'Attachment::fromContent(), fromFile() или fromBase64()',
                    null,
                    'invalid_attachment',
                );
            }
            self::checkLength("attachments[{$index}].filename", $attachment->filename, Limits::MAX_FILENAME_LENGTH);
            self::checkLength(
                "attachments[{$index}].contentType",
                $attachment->contentType,
                Limits::MAX_CONTENT_TYPE_LENGTH,
            );
            if (\strlen($attachment->contentBase64) > Limits::MAX_ATTACHMENT_BASE64_LENGTH) {
                throw new InvalidRequestException(
                    \sprintf(
                        'Вложение %s занимает %d символов base64 при лимите %d — API отклонит такой запрос с 422',
                        $attachment->filename,
                        \strlen($attachment->contentBase64),
                        Limits::MAX_ATTACHMENT_BASE64_LENGTH,
                    ),
                    null,
                    'invalid_attachment',
                );
            }
            $payload[] = $attachment->toArray();
        }

        return $payload;
    }

    /**
     * Приводит адресата к списку: `to: 'user@example.com'` — самый частый вызов.
     *
     * @return list<string>|null
     */
    private static function asList(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (\is_string($value)) {
            return [$value];
        }
        if (!\is_array($value)) {
            throw new InvalidRequestException(
                'Адресат должен быть строкой или списком строк',
                null,
                'invalid_request',
            );
        }

        return array_values($value);
    }

    /**
     * Приводит момент времени к ISO-8601 в UTC.
     *
     * Время уходит в UTC всегда: иначе окно фильтра съезжало бы на часовой
     * пояс хоста.
     */
    private static function moment(\DateTimeInterface|string|null $value): ?string
    {
        if ($value === null || \is_string($value)) {
            return $value;
        }

        return \DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.up');
    }

    /**
     * Собирает путь к письму, экранируя идентификатор.
     *
     * $emailId приходит снаружи, и без экранирования значение со слешем или
     * `?` молча превратилось бы в запрос к другому эндпоинту.
     */
    private static function emailPath(string $emailId, string $suffix = ''): string
    {
        return self::EMAILS_PATH . '/' . rawurlencode($emailId) . $suffix;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function itemList(mixed $data): array
    {
        if (!\is_array($data)) {
            return [];
        }

        return array_values(array_filter($data, static fn ($item) => \is_array($item)));
    }

    private static function checkCount(string $field, int $actual, int $limit): void
    {
        if ($actual > $limit) {
            throw new InvalidRequestException(
                \sprintf(
                    'В поле %s %d значений при лимите %d — API отклонит такой запрос с 422',
                    $field,
                    $actual,
                    $limit,
                ),
                null,
                'invalid_request',
            );
        }
    }

    private static function checkLength(string $field, mixed $value, int $limit): void
    {
        if (!\is_string($value)) {
            return;
        }
        $length = mb_strlen($value);
        if ($length > $limit) {
            throw new InvalidRequestException(
                \sprintf(
                    'Поле %s длиной %d символов при лимите %d — API отклонит такой запрос с 422',
                    $field,
                    $length,
                    $limit,
                ),
                null,
                'invalid_request',
            );
        }
    }

    /**
     * UUID версии 4 — значение по умолчанию для `Idempotency-Key`.
     *
     * Своя реализация вместо ramsey/uuid: десяток строк против внешней
     * зависимости в библиотеке, которую тянут в чужие проекты.
     */
    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40); // версия 4
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80); // вариант RFC 4122

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
