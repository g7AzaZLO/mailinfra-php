<?php

/**
 * Сверка моделей SDK с OpenAPI-спекой.
 *
 * Python и Node SDK получают модели кодогенератором, PHP-классы написаны
 * руками — значит, разойтись со спекой они могут молча. Этот тест читает
 * https://docs.mailinfra.ru/openapi/mailinfra-public.json и требует, чтобы совпадали три вещи:
 * состав полей каждой модели, значения перечислений и числовые ограничения
 * запроса из Limits.
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
use MailInfra\Model\SendResult;
use PHPUnit\Framework\TestCase;

final class SpecParityTest extends TestCase
{
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

        $fields = [];
        foreach ($constructor->getParameters() as $parameter) {
            $fields[] = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $parameter->getName()));
        }
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
        yield 'BatchSendResponseData' => [BatchSendResponseData::class, 'BatchSendResponseData'];
    }

    /**
     * SendResult добавляет к схеме метаданные запроса, которых в теле ответа
     * нет, но все поля схемы обязан нести.
     */
    public function testSendResultCoversResponseSchema(): void
    {
        $fields = self::wireFields(SendResult::class);

        foreach (self::schemaFields('EmailSendResponseData') as $field) {
            self::assertContains($field, $fields);
        }
        self::assertContains('replayed', $fields);
        self::assertContains('idempotency_key', $fields);
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

        self::assertSame($property[$keyword], $expected);
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
        yield 'emails' => ['BatchSendRequest', 'emails', 'maxItems', Limits::MAX_BATCH_SIZE];
    }

    /**
     * Потолок длины Idempotency-Key взят из описания самого заголовка, а не из
     * тела запроса.
     */
    public function testIdempotencyKeyLimitMatchesSpec(): void
    {
        $parameters = self::spec()['paths']['/emails']['post']['parameters'] ?? [];

        foreach ($parameters as $parameter) {
            if ($parameter['in'] === 'header' && $parameter['name'] === 'Idempotency-Key') {
                self::assertSame($parameter['schema']['maxLength'], Limits::IDEMPOTENCY_KEY_MAX_LENGTH);

                return;
            }
        }

        self::fail('в спеке нет заголовка Idempotency-Key у POST /emails');
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
            'GET /emails/{email_id}/events' => 'emails->events',
        ];

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
