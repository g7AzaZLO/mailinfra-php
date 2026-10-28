<?php

declare(strict_types=1);

namespace MailInfra\Tests;

use MailInfra\Exception\WebhookSignatureException;
use MailInfra\Webhooks;
use PHPUnit\Framework\TestCase;

final class WebhooksTest extends TestCase
{
    /**
     * Случаи из общих для бэкенда и всех SDK векторов подписи.
     *
     * @return list<array<string, mixed>>
     */
    private static function vectors(): array
    {
        $raw = file_get_contents(SharedResources::path('webhook-signatures.json', 'testdata'));
        self::assertIsString($raw, 'не прочитать вектора подписи');

        $document = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        self::assertNotEmpty($document['cases'], 'в векторах нет ни одного случая');

        return $document['cases'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function vector(string $name): array
    {
        foreach (self::vectors() as $case) {
            if ($case['name'] === $name) {
                return $case;
            }
        }

        self::fail("в векторах нет случая {$name}");
    }

    /**
     * Подпись считается ровно так же, как на бэкенде: один алгоритм, одни вектора.
     */
    public function testSignMatchesVectors(): void
    {
        foreach (self::vectors() as $case) {
            if ($case['valid'] !== true) {
                continue;
            }
            self::assertSame(
                $case['signature'],
                Webhooks::sign($case['body'], $case['secret'], $case['timestamp']),
                "подпись разошлась с бэкендом на случае {$case['name']}",
            );
        }
    }

    public function testVerifyMatchesVectors(): void
    {
        foreach (self::vectors() as $case) {
            self::assertSame(
                $case['valid'],
                // Часы заморожены на момент подписи: вектора зафиксированы во
                // времени и по-настоящему давно протухли.
                Webhooks::verify($case['body'], $case['secret'], $case['signature_header'], now: $case['timestamp']),
                "{$case['name']}: {$case['description']}",
            );
        }
    }

    public function testConstructEventReadsPayload(): void
    {
        $case = self::vector('delivered_cyrillic_subject');

        $event = Webhooks::constructEvent(
            $case['body'],
            $case['secret'],
            $case['signature_header'],
            now: $case['timestamp'],
        );

        self::assertSame('email.delivered', $event->type);
        self::assertSame('018e1b7a-1111-7000-aaaa-111111111111', $event->emailId());
        self::assertFalse($event->isTest());

        $email = $event->email();
        self::assertIsArray($email);
        self::assertSame('Ваш заказ подтверждён', $email['subject']);
    }

    public function testTestPingIsRecognized(): void
    {
        $case = self::vector('test_ping');

        $event = Webhooks::constructEvent(
            $case['body'],
            $case['secret'],
            $case['signature_header'],
            now: $case['timestamp'],
        );

        self::assertTrue($event->isTest());
        self::assertNull($event->email());
        self::assertNull($event->emailId());
    }

    /**
     * Пустое тело подписывается корректно, но событием не является — это
     * случай для verify, не для constructEvent.
     */
    public function testEmptyBodyIsNotAnEvent(): void
    {
        $case = self::vector('empty_body');

        self::assertTrue(
            Webhooks::verify($case['body'], $case['secret'], $case['signature_header'], now: $case['timestamp']),
        );

        try {
            Webhooks::constructEvent(
                $case['body'],
                $case['secret'],
                $case['signature_header'],
                now: $case['timestamp'],
            );
            self::fail('Ожидали WebhookSignatureException');
        } catch (WebhookSignatureException $exception) {
            self::assertSame(WebhookSignatureException::REASON_INVALID_JSON, $exception->getReason());
        }
    }

    /**
     * Каждый отказ приходит со своей машинной причиной: по ней в логах видно,
     * что чинить — конфиг, отправителя или обработчик.
     *
     * @dataProvider failureCases
     */
    public function testConstructEventFailureReasons(
        string $bodyKind,
        string $secretKind,
        string $headerKind,
        bool $freezeClock,
        string $expectedReason,
    ): void {
        $case = self::vector('delivered_cyrillic_subject');

        $body = $bodyKind === 'valid' ? $case['body'] : $bodyKind;
        $secret = $secretKind === 'valid' ? $case['secret'] : $secretKind;
        $header = match ($headerKind) {
            'valid' => 't=' . $case['timestamp'] . ',v1=' . Webhooks::sign($body, $case['secret'], $case['timestamp']),
            default => $headerKind,
        };

        try {
            Webhooks::constructEvent(
                $body,
                $secret,
                $header,
                now: $freezeClock ? $case['timestamp'] : null,
            );
            self::fail('Ожидали WebhookSignatureException');
        } catch (WebhookSignatureException $exception) {
            self::assertSame($expectedReason, $exception->getReason());
        }
    }

    /**
     * @return iterable<string, array{string, string, string, bool, string}>
     */
    public static function failureCases(): iterable
    {
        // Типовой источник пустого секрета — getenv() у незаданной переменной:
        // подпись не совпадёт никогда, и без явной причины это выглядит как
        // «отправитель шлёт мусор».
        yield 'пустой secret' => ['valid', '', 'valid', true, WebhookSignatureException::REASON_INVALID_SECRET];
        // Пустой secret важнее битого заголовка: reason обязан указывать на
        // конфиг получателя, а не на отправителя.
        yield 'пустой secret важнее битого заголовка' => [
            'valid', '', 't=1715461200', true, WebhookSignatureException::REASON_INVALID_SECRET,
        ];
        yield 'заголовок без v1' => [
            'valid', 'valid', 't=1715461200', true, WebhookSignatureException::REASON_MALFORMED_HEADER,
        ];
        yield 'нечисловой timestamp' => [
            'valid', 'valid', 't=вчера,v1=abc', true, WebhookSignatureException::REASON_MALFORMED_HEADER,
        ];
        yield 'пустой заголовок' => [
            'valid', 'valid', '', true, WebhookSignatureException::REASON_MALFORMED_HEADER,
        ];
        yield 'пустая v1' => [
            'valid', 'valid', 't=1715461200,v1=', true, WebhookSignatureException::REASON_MALFORMED_HEADER,
        ];
        yield 'чужой secret' => [
            'valid', 'whsec_другой', 'valid', true, WebhookSignatureException::REASON_SIGNATURE_MISMATCH,
        ];
        // Защита от replay: часы не заморожены, а вектор давно протух.
        yield 'протухшая подпись' => [
            'valid', 'valid', 'valid', false, WebhookSignatureException::REASON_TOO_OLD,
        ];
        yield 'тело не объект' => [
            '["не","событие"]', 'valid', 'valid', true, WebhookSignatureException::REASON_INVALID_JSON,
        ];
        yield 'тело не JSON' => [
            '<html>502</html>', 'valid', 'valid', true, WebhookSignatureException::REASON_INVALID_JSON,
        ];
        yield 'объект не по контракту' => [
            '{"hello":"world"}', 'valid', 'valid', true, WebhookSignatureException::REASON_INVALID_PAYLOAD,
        ];
    }

    /**
     * Пробелы вокруг частей допускает и HTTP, и нормализующие прокси: заголовок
     * `t=…, v1=…` обязан приниматься всеми SDK одинаково.
     */
    public function testSignatureHeaderToleratesSpaces(): void
    {
        $case = self::vector('delivered_cyrillic_subject');
        $spaced = 't=' . $case['timestamp'] . ', v1=' . $case['signature'];

        self::assertTrue(Webhooks::verify($case['body'], $case['secret'], $spaced, now: $case['timestamp']));
    }

    /**
     * Нулевой возраст отключает проверку времени — иногда это нужно при разборе
     * архивных доставок.
     */
    public function testZeroMaxAgeDisablesAgeCheck(): void
    {
        $case = self::vector('delivered_cyrillic_subject');

        self::assertTrue(
            Webhooks::verify($case['body'], $case['secret'], $case['signature_header'], maxAgeSeconds: 0),
        );
    }

    /**
     * Ротация секрета: в заголовке две подписи, и доставка проходит с любым
     * из двух секретов окна ротации — но не с посторонним.
     *
     * @dataProvider rotationCases
     */
    public function testRotationAcceptsAnyMatchingSignature(string $name, ?string $expectedReason): void
    {
        $case = self::vector($name);

        try {
            $event = Webhooks::constructEvent(
                $case['body'],
                $case['secret'],
                $case['signature_header'],
                now: $case['timestamp'],
            );
            self::assertNull($expectedReason, 'Ожидали отказ подписи');
            self::assertSame('email.delivered', $event->type);
        } catch (WebhookSignatureException $exception) {
            self::assertSame($expectedReason, $exception->getReason());
        }
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function rotationCases(): iterable
    {
        yield 'новый secret — первая v1' => ['rotation_new_secret', null];
        yield 'прежний secret — последняя v1' => ['rotation_previous_secret', null];
        yield 'посторонний secret' => ['rotation_unknown_secret', WebhookSignatureException::REASON_SIGNATURE_MISMATCH];
    }

    /**
     * Порядок подписей не важен: сверяется каждая, а не первая или последняя.
     */
    public function testRotationIgnoresSignatureOrder(): void
    {
        $new = self::vector('rotation_new_secret');
        $previous = self::vector('rotation_previous_secret');
        $swapped = "t={$new['timestamp']},v1={$previous['signature']},v1={$new['signature']}";

        self::assertTrue(Webhooks::verify($new['body'], $new['secret'], $swapped, now: $new['timestamp']));
        self::assertTrue(Webhooks::verify($new['body'], $previous['secret'], $swapped, now: $new['timestamp']));
    }

    /**
     * metadata письма из вебхука — то, ради чего его передают при отправке:
     * связать событие с заказом в своей системе.
     */
    public function testEventExposesEmailMetadata(): void
    {
        $secret = 'whsec_test';
        $timestamp = 1_715_461_200;
        $body = '{"id":"evt_1","type":"email.scheduled","created_at":"2026-11-01T06:00:00Z","data":{"email":'
            . '{"id":"018e1b7a-1111-7000-aaaa-111111111111","metadata":{"order_id":"A-1024","broken":7}}}}';
        $header = "t={$timestamp},v1=" . Webhooks::sign($body, $secret, $timestamp);

        $event = Webhooks::constructEvent($body, $secret, $header, now: $timestamp);

        self::assertSame('email.scheduled', $event->type);
        // Значение не по контракту отбрасывается, а не роняет обработчик.
        self::assertSame(['order_id' => 'A-1024'], $event->metadata());

        $ping = self::vector('test_ping');
        $pingEvent = Webhooks::constructEvent(
            $ping['body'],
            $ping['secret'],
            $ping['signature_header'],
            now: $ping['timestamp'],
        );
        self::assertSame([], $pingEvent->metadata());
    }
}
