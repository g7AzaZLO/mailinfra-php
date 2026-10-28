<?php

declare(strict_types=1);

namespace MailInfra\Tests;

use MailInfra\Enum\EmailStatus;
use MailInfra\Exception\InvalidRequestException;
use MailInfra\Exception\MalformedResponseException;
use MailInfra\Limits;
use MailInfra\Model\Attachment;
use MailInfra\Model\EmailListItem;
use PHPUnit\Framework\TestCase;

final class EmailsTest extends TestCase
{
    private const API_KEY = 'mi_live_0123456789abcdef0123456789abcdef';

    private function client(StubHttpClient $http, bool $autoIdempotency = true): TestableMailInfra
    {
        return new TestableMailInfra(
            apiKey: self::API_KEY,
            baseUrl: 'https://api.test/v1',
            autoIdempotency: $autoIdempotency,
            httpClient: $http,
        );
    }

    public function testSendBuildsPayload(): void
    {
        $http = new StubHttpClient(StubHttpClient::acceptedSend());

        $this->client($http)->emails->send(
            from: 'hello@mail.example.ru',
            to: 'user@example.com',
            subject: 'Заказ подтверждён',
            html: '<p>Спасибо!</p>',
            fromName: 'Магазин',
            cc: ['copy@example.com'],
            replyTo: 'support@mail.example.ru',
            tags: ['order'],
            variables: ['name' => 'Аня'],
        );

        $payload = $http->lastJson();
        // Имя поля в API — from, а не from_email: примеры из документации
        // обязаны работать в SDK без переименований.
        self::assertSame('hello@mail.example.ru', $payload['from']);
        self::assertSame('Магазин', $payload['from_name']);
        // Одиночный адрес приводится к списку — так его ждёт API.
        self::assertSame(['user@example.com'], $payload['to']);
        self::assertSame(['support@mail.example.ru'], $payload['reply_to']);
        self::assertArrayNotHasKey('bcc', $payload);
        self::assertArrayNotHasKey('text', $payload);
    }

    public function testSendSetsIdempotencyKeyAutomatically(): void
    {
        $http = new StubHttpClient(StubHttpClient::acceptedSend());

        $result = $this->client($http)->emails->send(
            from: 'hello@mail.example.ru',
            to: 'user@example.com',
            subject: 'Привет',
            text: 'Привет!',
        );

        $header = $http->last()['headers']['Idempotency-Key'] ?? null;
        self::assertNotNull($header, 'Idempotency-Key не проставлен');
        self::assertSame($header, $result->idempotencyKey);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            (string) $header,
        );
    }

    public function testSendUsesProvidedIdempotencyKey(): void
    {
        $http = new StubHttpClient(StubHttpClient::acceptedSend());

        $this->client($http)->emails->send(
            from: 'hello@mail.example.ru',
            to: 'user@example.com',
            subject: 'Привет',
            text: 'Привет!',
            idempotencyKey: 'order-42',
        );

        self::assertSame('order-42', $http->last()['headers']['Idempotency-Key']);
    }

    public function testSendWithoutIdempotencyKey(): void
    {
        $http = new StubHttpClient(StubHttpClient::acceptedSend());

        $this->client($http)->emails->send(
            from: 'hello@mail.example.ru',
            to: 'user@example.com',
            subject: 'Привет',
            text: 'Привет!',
            withoutIdempotencyKey: true,
        );

        self::assertArrayNotHasKey('Idempotency-Key', $http->last()['headers']);
    }

    public function testAutoIdempotencyDisabled(): void
    {
        $http = new StubHttpClient(StubHttpClient::acceptedSend());

        $this->client($http, autoIdempotency: false)->emails->send(
            from: 'hello@mail.example.ru',
            to: 'user@example.com',
            subject: 'Привет',
            text: 'Привет!',
        );

        self::assertArrayNotHasKey('Idempotency-Key', $http->last()['headers']);
    }

    /**
     * Пустой ключ бэкенд игнорирует, но запрос при этом считался бы
     * идемпотентным и повторялся после 5xx — и создавал второе письмо.
     */
    public function testSendRejectsBlankIdempotencyKey(): void
    {
        $http = new StubHttpClient();

        $this->expectException(InvalidRequestException::class);

        $this->client($http)->emails->send(
            from: 'hello@mail.example.ru',
            to: 'user@example.com',
            subject: 'Привет',
            text: 'Привет!',
            idempotencyKey: '   ',
        );
    }

    /**
     * Значение заголовка уходит байтами, а бэкенд читает их как latin-1:
     * кириллический ключ доехал бы до него мусором и вдвое длиннее. CR и LF
     * отбиваются заодно — это инъекция заголовков.
     *
     * @dataProvider nonAsciiIdempotencyKeys
     */
    public function testSendRejectsNonAsciiIdempotencyKey(string $key): void
    {
        $http = new StubHttpClient();

        $this->expectException(InvalidRequestException::class);

        $this->client($http)->emails->send(
            from: 'hello@mail.example.ru',
            to: 'user@example.com',
            subject: 'Привет',
            text: 'Привет!',
            idempotencyKey: $key,
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonAsciiIdempotencyKeys(): iterable
    {
        yield 'кириллица' => ['заказ-42'];
        yield 'эмодзи' => ['order-🎉'];
        yield 'перевод строки' => ["order-42\r\nX-Injected: 1"];
    }

    public function testSendRejectsTooLongIdempotencyKey(): void
    {
        $http = new StubHttpClient();

        $this->expectException(InvalidRequestException::class);

        $this->client($http)->emails->send(
            from: 'hello@mail.example.ru',
            to: 'user@example.com',
            subject: 'Привет',
            text: 'Привет!',
            idempotencyKey: str_repeat('x', Limits::IDEMPOTENCY_KEY_MAX_LENGTH + 1),
        );
    }

    /**
     * Идемпотентный повтор: бэкенд отвечает 200 вместо 202 и ставит заголовок.
     */
    public function testSendMarksReplayed(): void
    {
        $http = new StubHttpClient(StubHttpClient::json(
            200,
            '{"data":{"id":"018e1b7a-1111-7000-aaaa-111111111111","status":"QUEUED"}}',
            ['Idempotent-Replayed' => 'true', 'X-Request-ID' => 'req-1'],
        ));

        $result = $this->client($http)->emails->send(
            from: 'hello@mail.example.ru',
            to: 'user@example.com',
            subject: 'Привет',
            text: 'Привет!',
        );

        self::assertTrue($result->replayed);
        self::assertSame('req-1', $result->requestId);
        self::assertSame(EmailStatus::Queued, $result->status);
    }

    public function testSendEncodesAttachment(): void
    {
        $http = new StubHttpClient(StubHttpClient::acceptedSend());

        $this->client($http)->emails->send(
            from: 'billing@mail.example.ru',
            to: 'user@example.com',
            subject: 'Счёт',
            text: 'Счёт во вложении.',
            attachments: [Attachment::fromContent('счёт.pdf', 'PDF-1.4 счёт', 'application/pdf')],
        );

        $attachment = $http->lastJson()['attachments'][0];
        self::assertSame('счёт.pdf', $attachment['filename']);
        self::assertSame(base64_encode('PDF-1.4 счёт'), $attachment['content_base64']);
        self::assertSame('application/pdf', $attachment['content_type']);
    }

    public function testAttachmentDefaultsContentType(): void
    {
        $http = new StubHttpClient(StubHttpClient::acceptedSend());

        $this->client($http)->emails->send(
            from: 'billing@mail.example.ru',
            to: 'user@example.com',
            subject: 'Счёт',
            text: 'Счёт во вложении.',
            attachments: [Attachment::fromBase64('data.bin', base64_encode('binary'))],
        );

        self::assertSame(
            Attachment::DEFAULT_CONTENT_TYPE,
            $http->lastJson()['attachments'][0]['content_type'],
        );
    }

    /**
     * Лимиты спеки проверяются до сети: письмо с вложениями весит до ~25 МиБ,
     * и уезжать целиком ради 422 оно не должно.
     *
     * @dataProvider invalidSendParams
     *
     * @param array<string, mixed> $params
     */
    public function testSendValidatesLimitsLocally(array $params): void
    {
        $http = new StubHttpClient();

        try {
            $this->client($http)->emails->send(...$params);
            self::fail('Ожидали InvalidRequestException');
        } catch (InvalidRequestException) {
            self::assertSame([], $http->requests, 'Запрос ушёл, хотя параметры невалидны');
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidSendParams(): iterable
    {
        yield 'без from' => [['from' => '', 'to' => 'user@example.com']];
        yield 'без получателей' => [['from' => 'hello@mail.example.ru', 'to' => []]];
        yield 'много получателей' => [[
            'from' => 'hello@mail.example.ru',
            'to' => array_fill(0, Limits::MAX_RECIPIENTS + 1, 'user@example.com'),
        ]];
        yield 'много тегов' => [[
            'from' => 'hello@mail.example.ru',
            'to' => 'user@example.com',
            'tags' => array_fill(0, Limits::MAX_TAGS + 1, 'tag'),
        ]];
        yield 'длинная тема' => [[
            'from' => 'hello@mail.example.ru',
            'to' => 'user@example.com',
            'subject' => str_repeat('я', Limits::MAX_SUBJECT_LENGTH + 1),
        ]];
        yield 'длинный content_id' => [[
            'from' => 'hello@mail.example.ru',
            'to' => 'user@example.com',
            'attachments' => [
                Attachment::fromContent(
                    'logo.png',
                    'png',
                    'image/png',
                    str_repeat('c', Limits::MAX_CONTENT_ID_LENGTH + 1),
                ),
            ],
        ]];
        yield 'много ключей metadata' => [self::withMetadata(
            array_combine(
                array_map(static fn (int $i) => "k{$i}", range(1, Limits::MAX_METADATA_KEYS + 1)),
                array_fill(0, Limits::MAX_METADATA_KEYS + 1, 'v'),
            ),
        )];
        yield 'ключ metadata с пробелом' => [self::withMetadata(['order id' => 'A-1024'])];
        yield 'ключ metadata кириллицей' => [self::withMetadata(['заказ' => 'A-1024'])];
        yield 'длинный ключ metadata' => [self::withMetadata([
            str_repeat('k', Limits::MAX_METADATA_KEY_LENGTH + 1) => 'v',
        ])];
        // `$` в PCRE пропускает перевод строки в конце, а бэкенд — нет.
        yield 'ключ metadata с переводом строки' => [self::withMetadata(["order\n" => 'v'])];
        yield 'значение metadata не строка' => [self::withMetadata(['order_id' => 1024])];
        yield 'значение metadata с NUL' => [self::withMetadata(['order_id' => "A\0B"])];
        yield 'длинное значение metadata' => [self::withMetadata([
            'note' => str_repeat('я', Limits::MAX_METADATA_VALUE_LENGTH + 1),
        ])];
        yield 'metadata списком' => [self::withMetadata(['A-1024', 'user-7'])];
        yield 'metadata больше лимита в байтах' => [self::withMetadata(
            self::metadataOfJsonSize(Limits::MAX_METADATA_BYTES + 1),
        )];
        yield 'ttl меньше минуты' => [self::withParams(['ttl' => Limits::MIN_TTL_SECONDS - 1])];
        yield 'ttl больше суток' => [self::withParams(['ttl' => Limits::MAX_TTL_SECONDS + 1])];
        yield 'sendAt без часового пояса' => [self::withParams(['sendAt' => '2026-11-01 09:00:00'])];
        yield 'sendAt без времени' => [self::withParams(['sendAt' => '2026-11-01'])];
        yield 'sendAt не время' => [self::withParams(['sendAt' => 'завтра в девять'])];
    }

    /**
     * Минимальное валидное письмо с дополнительными параметрами.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private static function withParams(array $params): array
    {
        return ['from' => 'hello@mail.example.ru', 'to' => 'user@example.com', 'text' => 'Привет!'] + $params;
    }

    /**
     * @param array<array-key, mixed> $metadata
     *
     * @return array<string, mixed>
     */
    private static function withMetadata(array $metadata): array
    {
        return self::withParams(['metadata' => $metadata]);
    }

    /**
     * metadata, которое бэкенд (json.dumps с пробелами после `:` и `,`)
     * измерит ровно в $bytes байт: девять ключей `k1`…`k9`, восемь значений по
     * 500 символов и девятое — добирающее до нужного размера.
     *
     * @return array<string, string>
     */
    private static function metadataOfJsonSize(int $bytes): array
    {
        // `{}` + 9 × (`"kN": ""` — 8 байт) + 8 × `, ` + 8 × 500 = 4090 байт без девятого значения.
        $metadata = [];
        foreach (range(1, 8) as $i) {
            $metadata["k{$i}"] = str_repeat('x', Limits::MAX_METADATA_VALUE_LENGTH);
        }
        $metadata['k9'] = str_repeat('x', $bytes - 4090);

        return $metadata;
    }

    /**
     * Тема из кириллицы длиной ровно в лимит обязана проходить: лимит в спеке —
     * символьный, и счёт в байтах отбраковал бы её вдвое раньше срока.
     */
    public function testSubjectLimitCountsCharacters(): void
    {
        $http = new StubHttpClient(StubHttpClient::acceptedSend());

        $this->client($http)->emails->send(
            from: 'hello@mail.example.ru',
            to: 'user@example.com',
            subject: str_repeat('я', Limits::MAX_SUBJECT_LENGTH),
            text: 'Привет!',
        );

        self::assertCount(1, $http->requests);
    }

    public function testSendBatch(): void
    {
        $http = new StubHttpClient(StubHttpClient::json(202, '{"data":{"results":[
            {"index":0,"id":"018e1b7a-1111-7000-aaaa-111111111111","status":"QUEUED"},
            {"index":1,"error":{"status_code":422,"code":"domain_not_verified","message":"нет","details":{}}}
        ],"accepted_count":1,"failed_count":1}}'));

        $result = $this->client($http)->emails->sendBatch([
            ['from' => 'hello@mail.example.ru', 'to' => 'a@example.com', 'subject' => 'Раз', 'text' => '1'],
            ['from' => 'hello@mail.example.ru', 'to' => 'b@example.com', 'subject' => 'Два', 'text' => '2'],
        ]);

        self::assertSame(1, $result->acceptedCount);
        self::assertSame(1, $result->failedCount);
        self::assertTrue($result->results[0]->isAccepted());
        self::assertCount(1, $result->failed());
        self::assertSame('domain_not_verified', $result->failed()[0]->error?->code);
        self::assertFalse($result->replayed);
    }

    public function testSendBatchRejectsEmpty(): void
    {
        $this->expectException(InvalidRequestException::class);

        $this->client(new StubHttpClient())->emails->sendBatch([]);
    }

    public function testSendBatchRejectsTooLarge(): void
    {
        $email = ['from' => 'hello@mail.example.ru', 'to' => 'a@example.com', 'subject' => 'Раз', 'text' => '1'];

        $this->expectException(InvalidRequestException::class);

        $this->client(new StubHttpClient())->emails->sendBatch(array_fill(0, Limits::MAX_BATCH_SIZE + 1, $email));
    }

    /**
     * Ключ идемпотентности в элементе батча нельзя выбросить молча: его
     * добавляют ровно для защиты от дублей, а ключ у батча один на весь запрос.
     */
    public function testSendBatchRejectsIdempotencyKeyInItem(): void
    {
        $this->expectException(InvalidRequestException::class);

        $this->client(new StubHttpClient())->emails->sendBatch([[
            'from' => 'hello@mail.example.ru',
            'to' => 'a@example.com',
            'subject' => 'Раз',
            'text' => '1',
            'idempotencyKey' => 'order-42',
        ]]);
    }

    public function testSendBatchRejectsUnknownKey(): void
    {
        $this->expectException(InvalidRequestException::class);

        $this->client(new StubHttpClient())->emails->sendBatch([[
            'from' => 'hello@mail.example.ru',
            'to' => 'a@example.com',
            'subjekt' => 'опечатка',
        ]]);
    }

    public function testGetEmail(): void
    {
        $http = new StubHttpClient(StubHttpClient::json(200, '{"data":{
            "id":"018e1b7a-1111-7000-aaaa-111111111111","project_id":"018e1b7a-2222-7000-bbbb-222222222222",
            "from_email":"hello@mail.example.ru","from_name":null,"to_emails":["user@example.com"],
            "cc_emails":null,"bcc_emails":null,"reply_to":null,"subject":"Привет","html_body":"<p>hi</p>",
            "text_body":null,"headers":{},"status":"DELIVERED","source":"API","tags":[],
            "created_at":"2026-05-11T20:15:30.123456Z","sent_at":null,"delivered_at":null,"failed_at":null}}'));

        $email = $this->client($http)->emails->get('018e1b7a-1111-7000-aaaa-111111111111');

        self::assertSame(EmailStatus::Delivered, $email->status);
        self::assertNull($email->fromName);
        self::assertSame('2026-05-11T20:15:30', $email->createdAt->format('Y-m-d\TH:i:s'));
    }

    /**
     * Идентификатор приходит снаружи: без экранирования значение со слешем
     * молча превратилось бы в запрос к другому эндпоинту.
     */
    public function testEmailIdIsEscaped(): void
    {
        $http = new StubHttpClient(StubHttpClient::json(200, '{"data":[]}'));

        $this->client($http)->emails->events('../../admin/users');

        self::assertSame(
            'https://api.test/v1/emails/..%2F..%2Fadmin%2Fusers/events',
            $http->last()['url'],
        );
    }

    public function testListQuery(): void
    {
        $http = new StubHttpClient(StubHttpClient::listPage());

        $this->client($http)->emails->list(
            status: EmailStatus::Bounced,
            tag: 'order',
            from: new \DateTimeImmutable('2026-05-01 09:30:00', new \DateTimeZone('Europe/Moscow')),
            limit: 50,
        );

        $query = $http->lastQuery();
        self::assertSame('BOUNCED', $query['status']);
        self::assertSame('order', $query['tag']);
        self::assertSame('50', $query['limit']);
        // Время уходит в UTC: иначе окно фильтра съезжало бы на часовой пояс хоста.
        self::assertStringStartsWith('2026-05-01T06:30:00', $query['from']);
        self::assertArrayNotHasKey('to', $query);
    }

    public function testListWithoutFilters(): void
    {
        $http = new StubHttpClient(StubHttpClient::listPage());

        $this->client($http)->emails->list();

        self::assertSame([], $http->lastQuery());
    }

    public function testListParsesItems(): void
    {
        $http = new StubHttpClient(StubHttpClient::json(200, '{"data":[{
            "id":"018e1b7a-1111-7000-aaaa-111111111111","project_id":"018e1b7a-2222-7000-bbbb-222222222222",
            "from_email":"hello@mail.example.ru","to_emails":["user@example.com"],"subject":"Привет",
            "status":"BOUNCED","source":"API","tags":["order"],"created_at":"2026-05-11T20:15:30Z",
            "sent_at":null,"delivered_at":null,"bounce_type":"hard","bounce_category":"invalid-recipient"}],
            "has_more":false,"next_cursor":null}'));

        $page = $this->client($http)->emails->list();

        self::assertCount(1, $page->data);
        self::assertSame(EmailStatus::Bounced, $page->data[0]->status);
        self::assertSame('hard', $page->data[0]->bounceType?->value);
        self::assertSame(['order'], $page->data[0]->tags);
        self::assertFalse($page->hasMore);
        self::assertNull($page->nextCursor);
        // Поля, которых у письма нет, приходят пустыми, а не ошибкой разбора.
        self::assertSame([], $page->data[0]->metadata);
        self::assertNull($page->data[0]->scheduledAt);
        self::assertNull($page->data[0]->expiresAt);
    }

    /**
     * Неизвестное значение перечисления не должно ронять разбор ответа: бэкенд
     * может завести новую категорию отказа раньше, чем обновится SDK.
     */
    public function testUnknownEnumValueDoesNotBreakParsing(): void
    {
        $http = new StubHttpClient(StubHttpClient::json(200, '{"data":[{
            "id":"018e1b7a-1111-7000-aaaa-111111111111","project_id":"018e1b7a-2222-7000-bbbb-222222222222",
            "from_email":"hello@mail.example.ru","to_emails":["user@example.com"],"subject":"Привет",
            "status":"BOUNCED","source":"API","tags":[],"created_at":"2026-05-11T20:15:30Z",
            "sent_at":null,"delivered_at":null,"bounce_category":"quantum-tunneling"}],"has_more":false}'));

        $page = $this->client($http)->emails->list();

        self::assertNull($page->data[0]->bounceCategory);
    }

    /**
     * Отложенная отправка уходит в UTC: в каком поясе собран DateTime, сервер
     * не гадает.
     */
    public function testSendBuildsSchedulingPayload(): void
    {
        $http = new StubHttpClient(StubHttpClient::acceptedSend());

        $this->client($http)->emails->send(
            from: 'hello@mail.example.ru',
            to: 'user@example.com',
            subject: 'Напоминание',
            text: 'Оплатите заказ',
            metadata: ['order_id' => 'A-1024', 'user.id' => '7'],
            sendAt: new \DateTimeImmutable('2026-11-01 09:00:00', new \DateTimeZone('Europe/Moscow')),
            ttl: 3600,
        );

        $payload = $http->lastJson();
        self::assertSame(['order_id' => 'A-1024', 'user.id' => '7'], $payload['metadata']);
        self::assertSame('2026-11-01T06:00:00.000000Z', $payload['send_at']);
        self::assertSame(3600, $payload['ttl']);
    }

    /**
     * Строка с поясом уходит как есть: SDK не переписывает время, которое
     * вызывающий уже собрал по ISO 8601.
     *
     * @dataProvider zonedSendAtStrings
     */
    public function testSendAtStringWithOffsetPassesThrough(string $sendAt): void
    {
        $http = new StubHttpClient(StubHttpClient::acceptedSend());

        $this->client($http)->emails->send(
            from: 'hello@mail.example.ru',
            to: 'user@example.com',
            subject: 'Напоминание',
            text: 'Оплатите заказ',
            sendAt: $sendAt,
        );

        self::assertSame($sendAt, $http->lastJson()['send_at']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function zonedSendAtStrings(): iterable
    {
        yield 'смещение' => ['2026-11-01T09:00:00+03:00'];
        yield 'UTC' => ['2026-11-01T06:00:00Z'];
        yield 'доли секунды' => ['2026-11-01T06:00:00.123456Z'];
        yield 'смещение без двоеточия' => ['2026-11-01T09:00:00+0300'];
    }

    /**
     * Сервер отвечает SCHEDULED на отложенное письмо — значение обязано
     * разбираться, а не превращаться в ошибку контракта.
     */
    public function testSendReturnsScheduledStatus(): void
    {
        $http = new StubHttpClient(StubHttpClient::json(
            202,
            '{"data":{"id":"018e1b7a-1111-7000-aaaa-111111111111","status":"SCHEDULED","skipped_recipients":[]}}',
        ));

        $result = $this->client($http)->emails->send(
            from: 'hello@mail.example.ru',
            to: 'user@example.com',
            subject: 'Напоминание',
            text: 'Оплатите заказ',
            sendAt: '2026-11-01T09:00:00+03:00',
        );

        self::assertSame(EmailStatus::Scheduled, $result->status);
    }

    /**
     * Лимиты metadata — символьные для значений и байтовые для всего объекта,
     * как на бэкенде: кириллица ровно в лимит проходит, а размер JSON
     * меряется с пробелами json.dumps.
     */
    public function testMetadataLimitsMatchBackendCounting(): void
    {
        $http = new StubHttpClient(StubHttpClient::acceptedSend(), StubHttpClient::acceptedSend());
        $emails = $this->client($http)->emails;

        $emails->send(...self::withMetadata(['note' => str_repeat('я', Limits::MAX_METADATA_VALUE_LENGTH)]));
        $emails->send(...self::withMetadata(self::metadataOfJsonSize(Limits::MAX_METADATA_BYTES)));

        self::assertCount(2, $http->requests);
    }

    public function testAttachmentCarriesContentId(): void
    {
        $http = new StubHttpClient(StubHttpClient::acceptedSend());

        $this->client($http)->emails->send(
            from: 'hello@mail.example.ru',
            to: 'user@example.com',
            subject: 'Логотип',
            html: '<img src="cid:logo">',
            attachments: [
                Attachment::fromContent('logo.png', 'PNG', 'image/png', contentId: 'logo'),
                Attachment::fromContent('счёт.pdf', 'PDF', 'application/pdf'),
            ],
        );

        [$inline, $file] = $http->lastJson()['attachments'];
        self::assertSame('logo', $inline['content_id']);
        // Обычное вложение content_id не несёт вовсе: null бэкенд принял бы,
        // но вложение файлом от inline отличает именно отсутствие поля.
        self::assertArrayNotHasKey('content_id', $file);
    }

    public function testSendBatchSetsIdempotencyKeyAutomatically(): void
    {
        $http = new StubHttpClient(StubHttpClient::acceptedBatch());

        $result = $this->client($http)->emails->sendBatch([
            ['from' => 'hello@mail.example.ru', 'to' => 'a@example.com', 'subject' => 'Раз', 'text' => '1'],
        ]);

        $header = $http->last()['headers']['Idempotency-Key'] ?? null;
        self::assertNotNull($header, 'Idempotency-Key не проставлен');
        self::assertSame($header, $result->idempotencyKey);
    }

    public function testSendBatchUsesProvidedIdempotencyKey(): void
    {
        $http = new StubHttpClient(StubHttpClient::acceptedBatch());

        $this->client($http)->emails->sendBatch(
            [['from' => 'hello@mail.example.ru', 'to' => 'a@example.com', 'subject' => 'Раз', 'text' => '1']],
            idempotencyKey: str_repeat('k', Limits::BATCH_IDEMPOTENCY_KEY_MAX_LENGTH),
        );

        self::assertSame(
            str_repeat('k', Limits::BATCH_IDEMPOTENCY_KEY_MAX_LENGTH),
            $http->last()['headers']['Idempotency-Key'],
        );
    }

    public function testSendBatchWithoutIdempotencyKey(): void
    {
        $http = new StubHttpClient(StubHttpClient::acceptedBatch());

        $result = $this->client($http)->emails->sendBatch(
            [['from' => 'hello@mail.example.ru', 'to' => 'a@example.com', 'subject' => 'Раз', 'text' => '1']],
            withoutIdempotencyKey: true,
        );

        self::assertArrayNotHasKey('Idempotency-Key', $http->last()['headers']);
        self::assertNull($result->idempotencyKey);
    }

    /**
     * Потолок ключа у батча короче, чем у send(): бэкенд дописывает к нему
     * номер письма, и ключ каждого письма обязан уложиться в 255.
     */
    public function testSendBatchRejectsTooLongIdempotencyKey(): void
    {
        $http = new StubHttpClient();

        try {
            $this->client($http)->emails->sendBatch(
                [['from' => 'hello@mail.example.ru', 'to' => 'a@example.com', 'subject' => 'Раз', 'text' => '1']],
                idempotencyKey: str_repeat('k', Limits::BATCH_IDEMPOTENCY_KEY_MAX_LENGTH + 1),
            );
            self::fail('Ожидали InvalidRequestException');
        } catch (InvalidRequestException $exception) {
            self::assertSame('invalid_idempotency_key', $exception->getErrorCode());
            self::assertSame([], $http->requests);
        }
    }

    /**
     * Повтор батча, в котором заново не принято ни одного письма, приходит
     * 200 с заголовком — как у одиночной отправки.
     */
    public function testSendBatchMarksReplayed(): void
    {
        $http = new StubHttpClient(StubHttpClient::json(
            200,
            '{"data":{"results":[{"index":0,"id":"018e1b7a-1111-7000-aaaa-111111111111","status":"QUEUED"}],
            "accepted_count":1,"failed_count":0}}',
            ['Idempotent-Replayed' => 'true', 'X-Request-ID' => 'req-9'],
        ));

        $result = $this->client($http)->emails->sendBatch(
            [['from' => 'hello@mail.example.ru', 'to' => 'a@example.com', 'subject' => 'Раз', 'text' => '1']],
            idempotencyKey: 'digest-2026-11-01',
        );

        self::assertTrue($result->replayed);
        self::assertSame('digest-2026-11-01', $result->idempotencyKey);
        self::assertSame('req-9', $result->requestId);
    }

    public function testSendBatchItemAcceptsSchedulingFields(): void
    {
        $http = new StubHttpClient(StubHttpClient::acceptedBatch());

        $this->client($http)->emails->sendBatch([[
            'from' => 'hello@mail.example.ru',
            'to' => 'a@example.com',
            'subject' => 'Раз',
            'text' => '1',
            'metadata' => ['order_id' => 'A-1024'],
            'sendAt' => new \DateTimeImmutable('2026-11-01T06:00:00Z'),
            'ttl' => 600,
        ]]);

        $item = $http->lastJson()['emails'][0];
        self::assertSame(['order_id' => 'A-1024'], $item['metadata']);
        self::assertSame('2026-11-01T06:00:00.000000Z', $item['send_at']);
        self::assertSame(600, $item['ttl']);
    }

    /**
     * Неверное поле в элементе батча отбивается до сети, как и у send(): иначе
     * один кривой ttl стоил бы запроса на сотню писем.
     */
    public function testSendBatchValidatesItemLimits(): void
    {
        $http = new StubHttpClient();

        $this->expectException(InvalidRequestException::class);

        $this->client($http)->emails->sendBatch([[
            'from' => 'hello@mail.example.ru',
            'to' => 'a@example.com',
            'text' => '1',
            'ttl' => '600',
        ]]);
    }

    public function testListSendsCursorAndReadsPage(): void
    {
        $http = new StubHttpClient(StubHttpClient::listPage(
            [self::listItem('id-1'), self::listItem('id-2')],
            hasMore: true,
            nextCursor: 'cursor-2',
        ));

        $page = $this->client($http)->emails->list(tag: 'order', limit: 2, cursor: 'cursor-1');

        self::assertSame('cursor-1', $http->lastQuery()['cursor']);
        self::assertTrue($page->hasMore);
        self::assertSame('cursor-2', $page->nextCursor);
        // Страница считается и обходится как массив — код под 0.1.0 с
        // foreach и count() продолжает работать.
        self::assertCount(2, $page);
        $ids = [];
        foreach ($page as $email) {
            $ids[] = $email->id;
        }
        self::assertSame(['id-1', 'id-2'], $ids);
    }

    /**
     * Без has_more страница не по контракту: молча считать её последней значило
     * бы потерять письма на следующих страницах.
     */
    public function testListRequiresHasMore(): void
    {
        $http = new StubHttpClient(StubHttpClient::json(200, '{"data":[]}'));

        $this->expectException(MalformedResponseException::class);

        $this->client($http)->emails->list();
    }

    public function testListParsesSchedulingFields(): void
    {
        $http = new StubHttpClient(StubHttpClient::listPage([self::listItem('018e1b7a-1111-7000-aaaa-111111111111', [
            'status' => 'SCHEDULED',
            'metadata' => ['order_id' => 'A-1024'],
            'scheduled_at' => '2026-11-01T06:00:00Z',
            'expires_at' => '2026-11-01T07:00:00Z',
        ])]));

        $email = $this->client($http)->emails->list()->data[0];

        self::assertSame(EmailStatus::Scheduled, $email->status);
        self::assertSame(['order_id' => 'A-1024'], $email->metadata);
        self::assertSame('2026-11-01T06:00:00+00:00', $email->scheduledAt?->format(\DATE_ATOM));
        self::assertSame('2026-11-01T07:00:00+00:00', $email->expiresAt?->format(\DATE_ATOM));
    }

    public function testGetEmailParsesSchedulingFields(): void
    {
        $http = new StubHttpClient(StubHttpClient::json(200, '{"data":{
            "id":"018e1b7a-1111-7000-aaaa-111111111111","project_id":"018e1b7a-2222-7000-bbbb-222222222222",
            "from_email":"hello@mail.example.ru","from_name":null,"to_emails":["user@example.com"],
            "cc_emails":null,"bcc_emails":null,"reply_to":null,"subject":"Код входа","html_body":null,
            "text_body":"1234","headers":{},"status":"FAILED","source":"API","tags":[],
            "created_at":"2026-05-11T20:15:30Z","sent_at":null,"delivered_at":null,"failed_at":"2026-05-11T20:20:30Z",
            "metadata":{"login_id":"42"},"scheduled_at":null,"expires_at":"2026-05-11T20:20:30Z"}}'));

        $email = $this->client($http)->emails->get('018e1b7a-1111-7000-aaaa-111111111111');

        self::assertSame(['login_id' => '42'], $email->metadata);
        self::assertNull($email->scheduledAt);
        self::assertSame('2026-05-11T20:20:30+00:00', $email->expiresAt?->format(\DATE_ATOM));
    }

    /**
     * listAll сам идёт по курсору и несёт фильтры на каждую страницу: курсор
     * помнит только место в выборке, а не фильтры.
     */
    public function testListAllFollowsCursor(): void
    {
        $http = new StubHttpClient(
            StubHttpClient::listPage([self::listItem('id-1'), self::listItem('id-2')], true, 'c-2'),
            StubHttpClient::listPage([self::listItem('id-3')], true, 'c-3'),
            StubHttpClient::listPage([self::listItem('id-4')]),
        );

        $emails = iterator_to_array($this->client($http)->emails->listAll(status: EmailStatus::Bounced, limit: 2));

        // Ключи сквозные: iterator_to_array не затирает письма первой страницы второй.
        self::assertSame(['id-1', 'id-2', 'id-3', 'id-4'], array_map(static fn (EmailListItem $e) => $e->id, $emails));
        self::assertSame([0, 1, 2, 3], array_keys($emails));
        self::assertCount(3, $http->requests);
        self::assertArrayNotHasKey('cursor', $this->queryOf($http, 0));
        self::assertSame(['status' => 'BOUNCED', 'limit' => '2', 'cursor' => 'c-2'], $this->queryOf($http, 1));
        self::assertSame('c-3', $this->queryOf($http, 2)['cursor']);
    }

    /**
     * Генератор ленивый: без итерации запросов нет, а прерванный обход не
     * тянет лишних страниц.
     */
    public function testListAllIsLazy(): void
    {
        $http = new StubHttpClient(
            StubHttpClient::listPage([self::listItem('id-1'), self::listItem('id-2')], true, 'c-2'),
        );

        $emails = $this->client($http)->emails->listAll();
        self::assertSame([], $http->requests);

        foreach ($emails as $email) {
            self::assertSame('id-1', $email->id);
            break;
        }
        self::assertCount(1, $http->requests);
    }

    /**
     * Сервер обещал страницу, но курсор не сдвинулся: продолжать — значит
     * крутить один и тот же запрос бесконечно.
     *
     * @dataProvider stuckCursors
     */
    public function testListAllRejectsStuckCursor(?string $nextCursor): void
    {
        $http = new StubHttpClient(
            StubHttpClient::listPage([self::listItem('id-1')], true, 'c-1'),
            StubHttpClient::listPage([self::listItem('id-2')], true, $nextCursor),
        );

        $this->expectException(MalformedResponseException::class);

        iterator_to_array($this->client($http)->emails->listAll());
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function stuckCursors(): iterable
    {
        yield 'тот же курсор' => ['c-1'];
        yield 'курсора нет' => [null];
    }

    public function testCancel(): void
    {
        $http = new StubHttpClient(StubHttpClient::json(200, '{"data":{
            "id":"018e1b7a-1111-7000-aaaa-111111111111","status":"CANCELED","scheduled_at":"2026-11-01T06:00:00Z"}}'));

        $result = $this->client($http)->emails->cancel('018e1b7a-1111-7000-aaaa-111111111111');

        $request = $http->last();
        self::assertSame('POST', $request['method']);
        self::assertSame('https://api.test/v1/emails/018e1b7a-1111-7000-aaaa-111111111111/cancel', $request['url']);
        self::assertNull($request['body']);
        self::assertSame(EmailStatus::Canceled, $result->status);
        self::assertSame('2026-11-01T06:00:00+00:00', $result->scheduledAt?->format(\DATE_ATOM));
    }

    public function testReschedule(): void
    {
        $http = new StubHttpClient(StubHttpClient::json(200, '{"data":{
            "id":"018e1b7a-1111-7000-aaaa-111111111111","status":"SCHEDULED","scheduled_at":"2026-11-02T06:00:00Z"}}'));

        $result = $this->client($http)->emails->reschedule(
            '018e1b7a-1111-7000-aaaa-111111111111',
            new \DateTimeImmutable('2026-11-02 09:00:00', new \DateTimeZone('Europe/Moscow')),
        );

        $request = $http->last();
        self::assertSame('PATCH', $request['method']);
        self::assertSame('https://api.test/v1/emails/018e1b7a-1111-7000-aaaa-111111111111', $request['url']);
        self::assertSame(['send_at' => '2026-11-02T06:00:00.000000Z'], $http->lastJson());
        self::assertSame(EmailStatus::Scheduled, $result->status);
    }

    public function testRescheduleRejectsNaiveTime(): void
    {
        $http = new StubHttpClient();

        try {
            $this->client($http)->emails->reschedule('018e1b7a-1111-7000-aaaa-111111111111', date('Y-m-d H:i:s'));
            self::fail('Ожидали InvalidRequestException');
        } catch (InvalidRequestException $exception) {
            self::assertSame('invalid_request', $exception->getErrorCode());
            self::assertSame([], $http->requests);
        }
    }

    /**
     * Письмо в списке с обязательными полями схемы.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function listItem(string $id, array $overrides = []): array
    {
        return $overrides + [
            'id' => $id,
            'project_id' => '018e1b7a-2222-7000-bbbb-222222222222',
            'from_email' => 'hello@mail.example.ru',
            'to_emails' => ['user@example.com'],
            'subject' => 'Привет',
            'status' => 'DELIVERED',
            'source' => 'API',
            'tags' => [],
            'created_at' => '2026-05-11T20:15:30Z',
            'sent_at' => null,
            'delivered_at' => null,
        ];
    }

    /**
     * Query-параметры запроса с номером $index.
     *
     * @return array<string, string>
     */
    private function queryOf(StubHttpClient $http, int $index): array
    {
        $query = parse_url($http->requests[$index]['url'], \PHP_URL_QUERY);
        if (!\is_string($query)) {
            return [];
        }
        parse_str($query, $parsed);

        /** @var array<string, string> $parsed */
        return $parsed;
    }
}
