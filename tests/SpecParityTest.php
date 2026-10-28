<?php

/**
 * Сверка моделей SDK с OpenAPI-спекой.
 *
 * Python и Node SDK получают модели кодогенератором, PHP-классы написаны
 * руками — значит, разойтись со спекой они могут молча. Этот тест читает
 * https://docs.mailinfra.ru/openapi/mailinfra-public.json и требует, чтобы совпадали четыре вещи:
 * состав полей каждой модели, значения перечислений, числовые ограничения
 * запроса из Limits и аргументы методов — поля тела и query-параметры.
 *
 * Если тест красный — спека уехала вперёд: допишите поле, значение или
 * поправьте константу. Спека правится только через backend/scripts/gen_openapi.py.
 */

declare(strict_types=1);

namespace MailInfra\Tests;

use MailInfra\Enum\BounceCategory;
use MailInfra\Enum\BounceType;
use MailInfra\Enum\DkimSigningStatus;
use MailInfra\Enum\EmailEventType;
use MailInfra\Enum\EmailSource;
use MailInfra\Enum\EmailStatus;
use MailInfra\Enum\SuppressionReason;
use MailInfra\Limits;
use MailInfra\Model\Attachment;
use MailInfra\Model\BatchSendError;
use MailInfra\Model\BatchSendResponseData;
use MailInfra\Model\BatchSendResult;
use MailInfra\Model\EmailDetail;
use MailInfra\Model\EmailEvent;
use MailInfra\Model\EmailListItem;
use MailInfra\Model\EmailListPage;
use MailInfra\Model\EmailScheduleData;
use MailInfra\Model\SendResult;
use MailInfra\Resource\Emails;
use PHPUnit\Framework\TestCase;

final class SpecParityTest extends TestCase
{
    /**
     * Метаданные запроса, которые результат отправки несёт сверх схемы ответа.
     *
     * @var list<string>
     */
    private const REQUEST_META_FIELDS = ['idempotency_key', 'replayed', 'request_id'];

    /**
     * Аргументы send(), которые уходят заголовком, а не полем тела.
     *
     * @var list<string>
     */
    private const IDEMPOTENCY_ARGUMENTS = ['idempotencyKey', 'withoutIdempotencyKey'];

    /**
     * @return array<string, mixed>
     */
    private static function spec(): array
    {
        $path = SharedResources::path('mailinfra-public.json', 'openapi');
        $raw = file_get_contents($path);
        self::assertIsString($raw, 'не прочитать спеку ' . $path);

        return json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private static function schema(string $name): array
    {
        $schemas = self::spec()['components']['schemas'];
        self::assertArrayHasKey($name, $schemas, "в спеке нет схемы {$name}");

        return $schemas[$name];
    }

    /**
     * Имена полей модели в том виде, в каком они приходят от API.
     *
     * Свойства называются по-php'шному (camelCase), в JSON поля — snake_case.
     * Преобразование механическое, поэтому отдельная карта имён не нужна — и
     * не может рассинхронизироваться.
     *
     * @param class-string $class
     *
     * @return list<string>
     */
    private static function wireFields(string $class): array
    {
        $constructor = (new \ReflectionClass($class))->getConstructor();
        self::assertNotNull($constructor, "у {$class} нет конструктора");

        return self::wireNames(array_map(
            static fn (\ReflectionParameter $parameter) => $parameter->getName(),
            $constructor->getParameters(),
        ));
    }

    /**
     * Аргументы метода ресурса писем.
     *
     * @return list<string>
     */
    private static function arguments(string $method): array
    {
        return array_map(
            static fn (\ReflectionParameter $parameter) => $parameter->getName(),
            (new \ReflectionMethod(Emails::class, $method))->getParameters(),
        );
    }

    /**
     * camelCase → snake_case, отсортированно.
     *
     * @param list<string> $names
     *
     * @return list<string>
     */
    private static function wireNames(array $names): array
    {
        $fields = array_map(
            static fn (string $name) => strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name)),
            $names,
        );
        sort($fields);

        return $fields;
    }

    /**
     * @return list<string>
     */
    private static function schemaFields(string $name): array
    {
        // array_keys отдаёт int|string: числовое имя поля JSON превратился бы
        // в int и разъехался с именами из рефлексии.
        $fields = array_map(strval(...), array_keys(self::schema($name)['properties']));
        sort($fields);

        return $fields;
    }

    /**
     * У каждой модели SDK тот же набор полей, что у её схемы в спеке: ни
     * забытого нового поля, ни выдуманного лишнего.
     *
     * @dataProvider models
     *
     * @param class-string $class
     */
    public function testModelFieldsMatchSpec(string $class, string $schema): void
    {
        self::assertSame(self::schemaFields($schema), self::wireFields($class));
    }

    /**
     * @return iterable<string, array{class-string, string}>
     */
    public static function models(): iterable
    {
        yield 'EmailAttachmentInput' => [Attachment::class, 'EmailAttachmentInput'];
        yield 'EmailListItem' => [EmailListItem::class, 'EmailListItem'];
        yield 'EmailDetail' => [EmailDetail::class, 'EmailDetail'];
        yield 'EmailEventPublic' => [EmailEvent::class, 'EmailEventPublic'];
        yield 'BatchSendError' => [BatchSendError::class, 'BatchSendError'];
        yield 'BatchSendResult' => [BatchSendResult::class, 'BatchSendResult'];
        yield 'EmailListResponse' => [EmailListPage::class, 'EmailListResponse'];
        yield 'EmailScheduleData' => [EmailScheduleData::class, 'EmailScheduleData'];
    }

    /**
     * Результаты отправки добавляют к схеме метаданные запроса, которых в теле
     * ответа нет: флаг повтора, ключ идемпотентности и request_id. Кроме них —
     * ровно поля схемы.
     *
     * @dataProvider sendResults
     *
     * @param class-string $class
     */
    public function testSendResultsMatchSpecPlusRequestMeta(string $class, string $schema): void
    {
        $fields = self::wireFields($class);

        foreach (self::REQUEST_META_FIELDS as $field) {
            self::assertContains($field, $fields);
        }
        self::assertSame(self::schemaFields($schema), array_values(array_diff($fields, self::REQUEST_META_FIELDS)));
    }

    /**
     * @return iterable<string, array{class-string, string}>
     */
    public static function sendResults(): iterable
    {
        yield 'EmailSendResponseData' => [SendResult::class, 'EmailSendResponseData'];
        yield 'BatchSendResponseData' => [BatchSendResponseData::class, 'BatchSendResponseData'];
    }

    /**
     * Каждое поле тела отправки — аргумент send(), и элемент батча принимает
     * ровно те же ключи: новое поле API не может доехать до одной формы
     * отправки и забыться в другой.
     */
    public function testSendArgumentsMatchRequestSchema(): void
    {
        $bodyArguments = array_values(array_diff(self::arguments('send'), self::IDEMPOTENCY_ARGUMENTS));

        self::assertSame(self::schemaFields('EmailSendRequest'), self::wireNames($bodyArguments));

        $batchKeys = (new \ReflectionClassConstant(Emails::class, 'BATCH_ITEM_KEYS'))->getValue();
        self::assertIsArray($batchKeys);
        sort($batchKeys);
        sort($bodyArguments);
        self::assertSame($bodyArguments, $batchKeys);
    }

    /**
     * У list() есть аргумент на каждый query-параметр GET /emails, а listAll()
     * принимает те же аргументы, что и list().
     */
    public function testListArgumentsMatchQueryParameters(): void
    {
        $query = [];
        foreach (self::spec()['paths']['/emails']['get']['parameters'] as $parameter) {
            if ($parameter['in'] === 'query') {
                $query[] = $parameter['name'];
            }
        }
        sort($query);

        self::assertSame($query, self::wireNames(self::arguments('list')));
        self::assertSame(self::arguments('list'), self::arguments('listAll'));
    }

    /**
     * Перечисления SDK покрывают все значения спеки.
     *
     * Пропущенное значение означает, что ветвление по статусу у пользователя
     * молча не сработает на письме из будущей версии API.
     *
     * @dataProvider enums
     *
     * @param class-string<\BackedEnum> $enum
     */
    public function testEnumValuesMatchSpec(string $enum, string $schema): void
    {
        $expected = self::schema($schema)['enum'];
        sort($expected);

        $actual = array_map(static fn (\BackedEnum $case) => $case->value, $enum::cases());
        sort($actual);

        self::assertSame($expected, $actual);
    }

    /**
     * @return iterable<string, array{class-string<\BackedEnum>, string}>
     */
    public static function enums(): iterable
    {
        yield 'EmailStatus' => [EmailStatus::class, 'EmailStatus'];
        yield 'EmailEventType' => [EmailEventType::class, 'EmailEventType'];
        yield 'EmailSource' => [EmailSource::class, 'EmailSource'];
        yield 'BounceType' => [BounceType::class, 'BounceType'];
        yield 'BounceCategory' => [BounceCategory::class, 'BounceCategory'];
        yield 'DkimSigningStatus' => [DkimSigningStatus::class, 'DkimSigningStatus'];
        yield 'SuppressionReason' => [SuppressionReason::class, 'SuppressionReason'];
    }

    /**
     * Клиентская валидация отбраковывает ровно то, что отбраковал бы API.
     *
     * Разъехавшаяся в меньшую сторону константа тихо запрещает то, что API
     * принимает; в большую — возвращает 422 там, где SDK обещал проверку.
     *
     * @dataProvider limits
     */
    public function testLimitsMatchSpec(string $schema, string $field, string $keyword, int $expected): void
    {
        $property = self::schema($schema)['properties'][$field] ?? null;
        self::assertIsArray($property, "в спеке нет поля {$field} у {$schema}");
        self::assertArrayHasKey($keyword, $property, "в спеке нет {$keyword} у {$field}");

        // Сравниваем значения, а не тип литерала: minimum/maximum спека отдаёт
        // как 60.0, а maxItems — как 50, и оба — одно и то же целое число.
        self::assertIsNumeric($property[$keyword]);
        self::assertSame((float) $property[$keyword], (float) $expected);
    }

    /**
     * @return iterable<string, array{string, string, string, int}>
     */
    public static function limits(): iterable
    {
        yield 'to' => ['EmailSendRequest', 'to', 'maxItems', Limits::MAX_RECIPIENTS];
        yield 'cc' => ['EmailSendRequest', 'cc', 'maxItems', Limits::MAX_RECIPIENTS];
        yield 'bcc' => ['EmailSendRequest', 'bcc', 'maxItems', Limits::MAX_RECIPIENTS];
        yield 'reply_to' => ['EmailSendRequest', 'reply_to', 'maxItems', Limits::MAX_REPLY_TO];
        yield 'tags' => ['EmailSendRequest', 'tags', 'maxItems', Limits::MAX_TAGS];
        yield 'attachments' => ['EmailSendRequest', 'attachments', 'maxItems', Limits::MAX_ATTACHMENTS];
        yield 'subject' => ['EmailSendRequest', 'subject', 'maxLength', Limits::MAX_SUBJECT_LENGTH];
        yield 'from_name' => ['EmailSendRequest', 'from_name', 'maxLength', Limits::MAX_FROM_NAME_LENGTH];
        yield 'template' => ['EmailSendRequest', 'template', 'maxLength', Limits::MAX_TEMPLATE_LENGTH];
        yield 'filename' => ['EmailAttachmentInput', 'filename', 'maxLength', Limits::MAX_FILENAME_LENGTH];
        yield 'content_type' => ['EmailAttachmentInput', 'content_type', 'maxLength', Limits::MAX_CONTENT_TYPE_LENGTH];
        yield 'content_base64' => [
            'EmailAttachmentInput', 'content_base64', 'maxLength', Limits::MAX_ATTACHMENT_BASE64_LENGTH,
        ];
        yield 'content_id' => ['EmailAttachmentInput', 'content_id', 'maxLength', Limits::MAX_CONTENT_ID_LENGTH];
        yield 'ttl min' => ['EmailSendRequest', 'ttl', 'minimum', Limits::MIN_TTL_SECONDS];
        yield 'ttl max' => ['EmailSendRequest', 'ttl', 'maximum', Limits::MAX_TTL_SECONDS];
        yield 'emails' => ['BatchSendRequest', 'emails', 'maxItems', Limits::MAX_BATCH_SIZE];
    }

    /**
     * Лимиты metadata спека называет только текстом описания поля — сверяем с
     * ним, чтобы поднятый на бэкенде лимит не остался в SDK старым молча.
     */
    public function testMetadataLimitsMatchSpecDescription(): void
    {
        $description = self::schema('EmailSendRequest')['properties']['metadata']['description'] ?? '';
        self::assertIsString($description);

        self::assertStringContainsString('До ' . Limits::MAX_METADATA_KEYS . ' ключей', $description);
        self::assertStringContainsString('до ' . Limits::MAX_METADATA_VALUE_LENGTH . ' символов', $description);
    }

    /**
     * Потолок длины Idempotency-Key взят из описания самого заголовка, а не из
     * тела запроса. У батча он свой: бэкенд дописывает к ключу номер письма.
     *
     * @dataProvider idempotencyKeyLimits
     */
    public function testIdempotencyKeyLimitMatchesSpec(string $path, int $expected): void
    {
        $parameters = self::spec()['paths'][$path]['post']['parameters'] ?? [];

        foreach ($parameters as $parameter) {
            if ($parameter['in'] === 'header' && $parameter['name'] === 'Idempotency-Key') {
                self::assertSame($parameter['schema']['maxLength'], $expected);

                return;
            }
        }

        self::fail("в спеке нет заголовка Idempotency-Key у POST {$path}");
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function idempotencyKeyLimits(): iterable
    {
        yield 'POST /emails' => ['/emails', Limits::IDEMPOTENCY_KEY_MAX_LENGTH];
        yield 'POST /emails/batch' => ['/emails/batch', Limits::BATCH_IDEMPOTENCY_KEY_MAX_LENGTH];
    }

    /**
     * У каждой операции спеки есть метод SDK.
     *
     * Публичная поверхность API маленькая и целиком входит в v1 SDK, поэтому
     * «покрыто не всё» здесь — это забытый метод, а не осознанный объём.
     */
    public function testOperationCoverage(): void
    {
        $covered = [
            'POST /emails' => 'emails->send',
            'POST /emails/batch' => 'emails->sendBatch',
            'GET /emails' => 'emails->list',
            'GET /emails/{email_id}' => 'emails->get',
            'PATCH /emails/{email_id}' => 'emails->reschedule',
            'POST /emails/{email_id}/cancel' => 'emails->cancel',
            'GET /emails/{email_id}/events' => 'emails->events',
        ];

        foreach ($covered as $operation => $method) {
            $name = substr($method, \strlen('emails->'));
            self::assertTrue(method_exists(Emails::class, $name), "для {$operation} нет метода {$method}");
        }

        $operations = [];
        foreach (self::spec()['paths'] as $path => $item) {
            foreach (array_keys($item) as $method) {
                $operations[] = strtoupper((string) $method) . ' ' . $path;
            }
        }
        sort($operations);

        $expected = array_keys($covered);
        sort($expected);

        self::assertSame($expected, $operations);
    }
}
