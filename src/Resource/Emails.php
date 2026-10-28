<?php

/**
 * Ресурс писем: отправка, батч, чтение статусов и событий, отмена и перенос
 * запланированных писем.
 *
 * Об идемпотентности. `POST /emails` и `POST /emails/batch` принимают
 * `Idempotency-Key`, и SDK ставит его сам: ключ генерируется один раз на вызов
 * и переиспользуется всеми повторами внутри этого вызова. Поэтому сетевой сбой
 * или `503` не превращаются в два письма. Повторный вызов `send()` — это новый
 * ключ и новое письмо, как и ожидает вызывающий. Если ключа нет
 * (`withoutIdempotencyKey: true`, `autoIdempotency: false`), запрос помечается
 * неидемпотентным: дедуплицировать повтор бэкенду нечем.
 *
 * У батча ключ один на весь запрос: бэкенд выводит из него ключ каждого письма
 * (ключ запроса и номер письма), поэтому повтор не дублирует уже принятые
 * письма, а отклонённые пробует принять заново. Без ключа письма батча
 * коммитятся по одному без защиты от дублей, и такой батч повторяется только
 * на `429` — единственном отказе, который случается до приёма писем.
 */

declare(strict_types=1);

namespace MailInfra\Resource;

use MailInfra\Enum\EmailStatus;
use MailInfra\Exception\InvalidRequestException;
use MailInfra\Exception\MalformedResponseException;
use MailInfra\Internal\ApiResponse;
use MailInfra\Limits;
use MailInfra\MailInfra;
use MailInfra\Model\Attachment;
use MailInfra\Model\BatchSendResponseData;
use MailInfra\Model\EmailDetail;
use MailInfra\Model\EmailEvent;
use MailInfra\Model\EmailListItem;
use MailInfra\Model\EmailListPage;
use MailInfra\Model\EmailScheduleData;
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
        'from', 'to', 'subject', 'html', 'text', 'fromName', 'cc', 'bcc', 'replyTo',
        'headers', 'tags', 'template', 'variables', 'attachments', 'metadata', 'sendAt', 'ttl',
    ];

    /**
     * Время с часовым поясом в конце: `…T09:00:00Z`, `+03:00`, `+0300` или `+03`.
     */
    private const SEND_AT_PATTERN = '/\d{2}:\d{2}(?::\d{2}(?:[.,]\d+)?)?\s*(?:Z|[+-]\d{2}(?::?\d{2})?)$/i';

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
     * @param list<Attachment>|null $attachments Вложения; с `contentId` — inline-картинки.
     * @param string|null $idempotencyKey Свой ключ идемпотентности; по умолчанию
     *                                    SDK генерирует uuid4 на каждый вызов.
     * @param bool $withoutIdempotencyKey Отправить вообще без ключа. Осознанный
     *                                    отказ от защиты: бэкенду будет нечем
     *                                    распознать повтор, и SDK перестанет
     *                                    ретраить запрос после 5xx и таймаутов.
     * @param array<string, string>|null $metadata Ваши пары «ключ — значение» (номер
     *                                            заказа, id пользователя): вернутся
     *                                            в list(), get() и в каждом вебхуке
     *                                            письма. До 20 ключей, значения —
     *                                            строки до 500 символов.
     * @param \DateTimeInterface|string|null $sendAt Отложенная отправка, не дальше
     *                                               7 дней вперёд: письмо получит
     *                                               статус SCHEDULED, и до отправки
     *                                               его можно отменить (cancel) или
     *                                               перенести (reschedule). Строка —
     *                                               ISO 8601 с часовым поясом. Время
     *                                               в прошлом — отправить сразу.
     * @param int|null $ttl Срок жизни письма, секунды (60..86400), от sendAt или от
     *                      приёма. Не дошедшее за это время письмо не отправляется
     *                      и не повторяется: FAILED с причиной expired или отказ с
     *                      категорией delivery-expired.
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
        ?array $metadata = null,
        \DateTimeInterface|string|null $sendAt = null,
        ?int $ttl = null,
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
            'metadata' => $metadata,
            'sendAt' => $sendAt,
            'ttl' => $ttl,
        ]);

        $key = $this->resolveIdempotencyKey(
            $idempotencyKey,
            $withoutIdempotencyKey,
            Limits::IDEMPOTENCY_KEY_MAX_LENGTH,
        );

        $response = $this->client->request(
            method: 'POST',
            path: self::EMAILS_PATH,
            body: $payload,
            headers: self::idempotencyHeaders($key),
            // Без Idempotency-Key бэкенду нечем распознать повтор
            // (email_service.send_email дедуплицирует только по ключу), поэтому
            // такой запрос неидемпотентен: повтор после 5xx создал бы второе письмо.
            idempotent: $key !== null,
        );

        return SendResult::fromArray($response->arrayData(), self::isReplayed($response), $key, $response->requestId);
    }

    /**
     * Отправляет до 100 писем одним запросом.
     *
     * Батч возвращает `202`, даже если часть писем не принята: проверяйте
     * `$result->error` у каждого элемента `results`, а не только отсутствие
     * исключения. `Idempotency-Key` SDK ставит сам, как у send(): ключ один на
     * весь батч, бэкенд выводит из него ключ каждого письма, и повтор после
     * `5xx`, таймаута или обрыва соединения не продублирует уже принятые письма.
     * Без ключа SDK повторяет батч только на `429`.
     *
     * @param list<array<string, mixed>> $emails Наборы параметров, как у send(),
     *                                           без ключей идемпотентности.
     * @param string|null $idempotencyKey Свой ключ идемпотентности батча, до 252
     *                                    символов; по умолчанию SDK генерирует
     *                                    uuid4 на каждый вызов.
     * @param bool $withoutIdempotencyKey Отправить батч без ключа. Повтор после
     *                                    5xx и таймаутов станет небезопасен, и
     *                                    SDK перестанет его ретраить.
     *
     * @throws InvalidRequestException Пустой или слишком большой батч, элемент
     *                                 с неизвестным параметром или параметры
     *                                 письма не проходят ограничения API.
     */
    public function sendBatch(
        array $emails,
        ?string $idempotencyKey = null,
        bool $withoutIdempotencyKey = false,
    ): BatchSendResponseData {
        $payloads = self::batchPayloads($emails);

        $key = $this->resolveIdempotencyKey(
            $idempotencyKey,
            $withoutIdempotencyKey,
            Limits::BATCH_IDEMPOTENCY_KEY_MAX_LENGTH,
        );

        $response = $this->client->request(
            method: 'POST',
            path: self::BATCH_PATH,
            body: ['emails' => $payloads],
            headers: self::idempotencyHeaders($key),
            // Без ключа письма батча коммитятся по одному без защиты от дублей:
            // повтор после 5xx или обрыва отправил бы часть писем второй раз.
            idempotent: $key !== null,
        );

        return BatchSendResponseData::fromArray(
            $response->arrayData(),
            self::isReplayed($response),
            $key,
            $response->requestId,
        );
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
     * Возвращает страницу писем проекта по фильтрам, новые сначала.
     *
     * Следующая страница — тот же вызов с теми же фильтрами и
     * `cursor: $page->nextCursor`, пока `$page->hasMore`: курсор помнит только
     * место в выборке, а не фильтры. Обойти все страницы сразу — listAll().
     *
     * @param \DateTimeInterface|string|null $from Начало интервала по времени создания.
     * @param \DateTimeInterface|string|null $to Конец интервала.
     * @param int|null $limit Размер страницы, 1..100 (по умолчанию 25 на стороне API).
     * @param string|null $cursor `nextCursor` предыдущей страницы; null — первая страница.
     */
    public function list(
        ?EmailStatus $status = null,
        ?string $fromEmail = null,
        ?string $toEmail = null,
        ?string $tag = null,
        \DateTimeInterface|string|null $from = null,
        \DateTimeInterface|string|null $to = null,
        ?int $limit = null,
        ?string $cursor = null,
    ): EmailListPage {
        $query = array_filter([
            'status' => $status?->value,
            'from_email' => $fromEmail,
            'to_email' => $toEmail,
            'tag' => $tag,
            'from' => self::moment($from),
            'to' => self::moment($to),
            'limit' => $limit,
            'cursor' => $cursor,
        ], static fn ($value) => $value !== null);

        $response = $this->client->request('GET', self::EMAILS_PATH, query: $query);

        return EmailListPage::fromArray($response->body);
    }

    /**
     * Обходит все письма по фильтрам, сам листая страницы по курсору.
     *
     * ```php
     * foreach ($client->emails->listAll(status: EmailStatus::Bounced) as $email) {
     *     // …
     * }
     * ```
     *
     * Генератор ленивый: страница запрашивается, когда до неё дошёл `foreach`,
     * поэтому ошибка API всплывает на итерации, а не при вызове, а прерванный
     * `break` обход лишних страниц не запрашивает. Параметры — как у list().
     *
     * @param \DateTimeInterface|string|null $from Начало интервала по времени создания.
     * @param \DateTimeInterface|string|null $to Конец интервала.
     * @param int|null $limit Размер одной страницы, а не потолок всего обхода.
     * @param string|null $cursor С какой страницы начать; null — с первой.
     *
     * @return \Generator<int, EmailListItem, mixed, void>
     *
     * @throws MalformedResponseException Сервер обещал следующую страницу, но
     *                                    не дал нового курсора — без этой
     *                                    проверки обход зациклился бы.
     */
    public function listAll(
        ?EmailStatus $status = null,
        ?string $fromEmail = null,
        ?string $toEmail = null,
        ?string $tag = null,
        \DateTimeInterface|string|null $from = null,
        \DateTimeInterface|string|null $to = null,
        ?int $limit = null,
        ?string $cursor = null,
    ): \Generator {
        while (true) {
            $page = $this->list(
                status: $status,
                fromEmail: $fromEmail,
                toEmail: $toEmail,
                tag: $tag,
                from: $from,
                to: $to,
                limit: $limit,
                cursor: $cursor,
            );
            foreach ($page->data as $email) {
                yield $email;
            }

            if (!$page->hasMore) {
                return;
            }
            if ($page->nextCursor === null || $page->nextCursor === $cursor) {
                throw new MalformedResponseException(
                    'Ответ API не по контракту: has_more=true, но next_cursor не ведёт на новую страницу',
                    null,
                    'malformed_response',
                );
            }
            $cursor = $page->nextCursor;
        }
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
     * Отменяет запланированное письмо; резерв квоты возвращается.
     *
     * Нужно право на отправку — то же, что у send(). После `5xx` и сбоя сети
     * SDK повторяет отмену сам: дважды письмо не отменится. Но если первая
     * попытка всё-таки дошла, повтор получит EmailNotScheduledException с
     * `CANCELED` в `getDetails()["status"]` — это тоже успех.
     *
     * @throws \MailInfra\Exception\EmailNotScheduledException Письмо уже не
     *                                                         запланировано: отправлено,
     *                                                         отправляется или отменено.
     * @throws \MailInfra\Exception\NotFoundException Письма нет или оно принадлежит другому проекту.
     */
    public function cancel(string $emailId): EmailScheduleData
    {
        $response = $this->client->request('POST', self::emailPath($emailId, '/cancel'));

        return EmailScheduleData::fromArray($response->arrayData());
    }

    /**
     * Переносит запланированное письмо на новое время.
     *
     * Срок жизни (`ttl`) переезжает вместе с временем отправки.
     *
     * @param \DateTimeInterface|string $sendAt Новое время с часовым поясом, не
     *                                          дальше 7 дней от приёма письма.
     *                                          Время в прошлом — отправить сейчас.
     *
     * @throws InvalidRequestException У строки sendAt нет часового пояса.
     * @throws \MailInfra\Exception\SendAtTooFarException Новое время дальше 7 дней от приёма письма.
     * @throws \MailInfra\Exception\EmailNotScheduledException Письмо уже не запланировано.
     * @throws \MailInfra\Exception\NotFoundException Письма нет или оно принадлежит другому проекту.
     */
    public function reschedule(string $emailId, \DateTimeInterface|string $sendAt): EmailScheduleData
    {
        $response = $this->client->request(
            method: 'PATCH',
            path: self::emailPath($emailId),
            body: ['send_at' => self::sendAtPayload($sendAt)],
        );

        return EmailScheduleData::fromArray($response->arrayData());
    }

    /**
     * Решает, с каким ключом уйдёт отправка.
     *
     * @param int $maxLength Потолок длины ключа: у батча он короче, чем у send().
     */
    private function resolveIdempotencyKey(?string $provided, bool $without, int $maxLength): ?string
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
        if (\strlen($provided) > $maxLength) {
            throw new InvalidRequestException(
                \sprintf('Idempotency-Key длиннее %d символов — API отклонит такой запрос с 422', $maxLength),
                null,
                'invalid_idempotency_key',
            );
        }

        return $provided;
    }

    /**
     * @return array<string, string>
     */
    private static function idempotencyHeaders(?string $key): array
    {
        return $key === null ? [] : [self::IDEMPOTENCY_KEY_HEADER => $key];
    }

    /**
     * Идемпотентный ли это повтор: 200 вместо 202, а заголовок дублирует
     * признак для прокси, которые статус переписывают.
     */
    private static function isReplayed(ApiResponse $response): bool
    {
        return $response->statusCode === 200
            || strtolower((string) $response->header(self::IDEMPOTENT_REPLAYED_HEADER)) === 'true';
    }

    /**
     * Проверяет батч и собирает тела его писем.
     *
     * @param list<array<string, mixed>> $emails
     *
     * @return list<array<string, mixed>>
     */
    private static function batchPayloads(array $emails): array
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
            foreach (array_keys($item) as $key) {
                self::checkBatchItemKey($index, $key);
            }
            $payloads[] = self::buildPayload($item);
        }

        return $payloads;
    }

    /**
     * Отбивает неизвестный параметр элемента батча и ключ идемпотентности в нём.
     */
    private static function checkBatchItemKey(int $index, int|string $key): void
    {
        // Ключ у батча один на весь запрос, и тихо выбросить ключ письма
        // нельзя: его добавляют ровно для защиты от дублей.
        if (\in_array($key, ['idempotencyKey', 'withoutIdempotencyKey'], true)) {
            throw new InvalidRequestException(
                "Письмо #{$index} в батче задаёт {$key}, а ключ идемпотентности у батча один на весь "
                . 'запрос: передайте его аргументом sendBatch(idempotencyKey: …) — бэкенд сам выведет из '
                . 'него ключ каждого письма',
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
            'metadata' => self::metadataPayload($params['metadata'] ?? null),
            'send_at' => self::sendAtPayload($params['sendAt'] ?? null),
            'ttl' => self::ttlPayload($params['ttl'] ?? null),
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
            self::checkLength(
                "attachments[{$index}].contentId",
                $attachment->contentId,
                Limits::MAX_CONTENT_ID_LENGTH,
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
     * Проверяет metadata по тем же правилам, что и бэкенд
     * (app.services.email_metadata.normalize_metadata).
     *
     * @return array<string, string>|null
     */
    private static function metadataPayload(mixed $metadata): ?array
    {
        if ($metadata === null || $metadata === []) {
            return null;
        }
        // Список вместо карты — почти наверняка ошибка вызова: json_encode
        // отправил бы его JSON-массивом, и API отклонил бы запрос.
        if (!\is_array($metadata) || array_is_list($metadata)) {
            throw self::invalidMetadata('metadata должен быть картой «ключ → строка», а не списком');
        }
        self::checkCount('metadata', \count($metadata), Limits::MAX_METADATA_KEYS);

        $keyPattern = \sprintf('/^[A-Za-z0-9_.\-]{1,%d}$/D', Limits::MAX_METADATA_KEY_LENGTH);
        foreach ($metadata as $key => $value) {
            if (preg_match($keyPattern, (string) $key) !== 1) {
                throw self::invalidMetadata(\sprintf(
                    "Ключ metadata '%s' недопустим: 1–%d символов из латиницы, цифр, '_', '-' и '.'",
                    $key,
                    Limits::MAX_METADATA_KEY_LENGTH,
                ));
            }
            if (!\is_string($value) || str_contains($value, "\0")) {
                throw self::invalidMetadata("Значение metadata['{$key}'] должно быть строкой без NUL-символов");
            }
            self::checkLength("metadata[{$key}]", $value, Limits::MAX_METADATA_VALUE_LENGTH);
        }
        self::checkMetadataSize($metadata);

        return $metadata;
    }

    /**
     * Проверяет размер metadata в JSON — в тех же байтах, что считает бэкенд.
     *
     * Бэкенд меряет `json.dumps(..., ensure_ascii=False)`, а он ставит пробел
     * после каждого `:` и `,`. Компактный json_encode короче ровно на эти
     * пробелы: по два на пару без одного.
     *
     * @param array<string, string> $metadata
     */
    private static function checkMetadataSize(array $metadata): void
    {
        try {
            $json = json_encode(
                $metadata,
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_LINE_TERMINATORS
                | \JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $exception) {
            throw self::invalidMetadata('metadata не кодируется в JSON: значения должны быть в UTF-8', $exception);
        }

        $size = \strlen($json) + 2 * \count($metadata) - 1;
        if ($size > Limits::MAX_METADATA_BYTES) {
            throw self::invalidMetadata(\sprintf(
                'metadata занимает %d байт в JSON при лимите %d — API отклонит такой запрос с 422',
                $size,
                Limits::MAX_METADATA_BYTES,
            ));
        }
    }

    private static function invalidMetadata(string $message, ?\Throwable $previous = null): InvalidRequestException
    {
        return new InvalidRequestException($message, null, 'invalid_request', [], null, $previous);
    }

    /**
     * Приводит время отложенной отправки к ISO-8601 и проверяет часовой пояс.
     *
     * Без пояса API не знает, чьё это «09:00», и отвечает 400 send_at_invalid.
     * Типовой источник такой строки — `date('Y-m-d H:i:s')`, поэтому она
     * отбивается до сети. DateTimeInterface пояс несёт всегда и уходит в UTC.
     */
    private static function sendAtPayload(mixed $sendAt): ?string
    {
        if ($sendAt === null || $sendAt instanceof \DateTimeInterface) {
            return self::moment($sendAt);
        }
        if (\is_string($sendAt) && preg_match(self::SEND_AT_PATTERN, trim($sendAt)) === 1) {
            return $sendAt;
        }

        throw new InvalidRequestException(
            'sendAt должен быть DateTimeInterface или строкой ISO 8601 с часовым поясом, например '
            . "'2026-11-01T09:00:00+03:00': без пояса API не знает, чьё это время, и отклонит запрос",
            null,
            'invalid_request',
        );
    }

    /**
     * Проверяет срок жизни письма.
     */
    private static function ttlPayload(mixed $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }
        if (!\is_int($ttl) || $ttl < Limits::MIN_TTL_SECONDS || $ttl > Limits::MAX_TTL_SECONDS) {
            throw new InvalidRequestException(
                \sprintf(
                    'ttl — целое число секунд от %d до %d, а передано %s — API отклонит такой запрос с 422',
                    Limits::MIN_TTL_SECONDS,
                    Limits::MAX_TTL_SECONDS,
                    \is_int($ttl) ? (string) $ttl : get_debug_type($ttl),
                ),
                null,
                'invalid_request',
            );
        }

        return $ttl;
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
     * пояс хоста. Строка уходит как есть.
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
