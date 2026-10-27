<?php

declare(strict_types=1);

namespace MailInfra\Tests;

use MailInfra\Enum\EmailStatus;
use MailInfra\Exception\InvalidRequestException;
use MailInfra\Limits;
use MailInfra\Model\Attachment;
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
        // Заголовок батч не принимает вовсе — бэкенд коммитит письма по одному.
        self::assertArrayNotHasKey('Idempotency-Key', $http->last()['headers']);
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
     * добавляют ровно для защиты от дублей, а эндпоинт его не принимает.
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
        $http = new StubHttpClient(StubHttpClient::json(200, '{"data":[]}'));

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
        $http = new StubHttpClient(StubHttpClient::json(200, '{"data":[]}'));

        $this->client($http)->emails->list();

        self::assertSame([], $http->lastQuery());
    }

    public function testListParsesItems(): void
    {
        $http = new StubHttpClient(StubHttpClient::json(200, '{"data":[{
            "id":"018e1b7a-1111-7000-aaaa-111111111111","project_id":"018e1b7a-2222-7000-bbbb-222222222222",
            "from_email":"hello@mail.example.ru","to_emails":["user@example.com"],"subject":"Привет",
            "status":"BOUNCED","source":"API","tags":["order"],"created_at":"2026-05-11T20:15:30Z",
            "sent_at":null,"delivered_at":null,"bounce_type":"hard","bounce_category":"invalid-recipient"}]}'));

        $emails = $this->client($http)->emails->list();

        self::assertCount(1, $emails);
        self::assertSame(EmailStatus::Bounced, $emails[0]->status);
        self::assertSame('hard', $emails[0]->bounceType?->value);
        self::assertSame(['order'], $emails[0]->tags);
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
            "sent_at":null,"delivered_at":null,"bounce_category":"quantum-tunneling"}]}'));

        $emails = $this->client($http)->emails->list();

        self::assertNull($emails[0]->bounceCategory);
    }
}
