<?php

declare(strict_types=1);

namespace MailInfra\Tests;

use MailInfra\RetryPolicy;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase
{
    /**
     * @dataProvider retryAfterValues
     */
    public function testParseRetryAfterSeconds(?string $value, ?float $expected): void
    {
        self::assertSame($expected, RetryPolicy::parseRetryAfter($value, 1_715_461_200));
    }

    /**
     * @return iterable<string, array{?string, ?float}>
     */
    public static function retryAfterValues(): iterable
    {
        yield 'секунды' => ['3', 3.0];
        yield 'с пробелами' => [' 3 ', 3.0];
        yield 'дробные' => ['0.5', 0.5];
        yield 'ноль' => ['0', 0.0];
        // Отрицательное значение — «уже можно»: пауза 0, а не отказ от подсказки.
        yield 'отрицательное' => ['-5', 0.0];
        yield 'нет заголовка' => [null, null];
        yield 'пустая строка' => ['', null];
        yield 'мусор' => ['через час', null];
        // Научная запись даёт INF: такой заголовок от прокси превратил бы 429
        // в повтор без паузы — ровно вместо того backoff'а, ради которого он и прислан.
        yield 'переполнение' => ['1e400', null];
    }

    /**
     * HTTP допускает и дату — её умеют присылать прокси и CDN перед API.
     */
    public function testParseRetryAfterDate(): void
    {
        // Момент отсчёта берём тем же парсером, что и значение заголовка:
        // с зашитым вручную unix-временем легко ошибиться на час и получить
        // «дату в прошлом» там, где проверяется дата в будущем.
        $now = strtotime('Sat, 11 May 2024 21:00:00 GMT');
        self::assertIsInt($now);

        self::assertSame(30.0, RetryPolicy::parseRetryAfter('Sat, 11 May 2024 21:00:30 GMT', $now));
        // Дата в прошлом — «уже можно».
        self::assertSame(0.0, RetryPolicy::parseRetryAfter('Sat, 11 May 2024 20:59:00 GMT', $now));
    }

    /**
     * @dataProvider retryableStatuses
     */
    public function testShouldRetryStatus(int $status, bool $idempotent, bool $expected): void
    {
        self::assertSame($expected, (new RetryPolicy())->shouldRetryStatus($status, $idempotent));
    }

    /**
     * @return iterable<string, array{int, bool, bool}>
     */
    public static function retryableStatuses(): iterable
    {
        yield '429 идемпотентный' => [429, true, true];
        yield '429 неидемпотентный' => [429, false, true];
        yield '503 идемпотентный' => [503, true, true];
        // Неидемпотентный запрос повторяем только там, где ничего не принято.
        yield '503 неидемпотентный' => [503, false, false];
        yield '500' => [500, true, true];
        yield '408' => [408, true, true];
        yield '425' => [425, true, true];
        // 409 idempotency_in_flight: параллельный запрос ещё выполняется,
        // крутить повторы вслепую нельзя.
        yield '409' => [409, true, false];
        yield '422' => [422, true, false];
        yield '404' => [404, true, false];
    }

    public function testNextDelayGrowsAndIsCapped(): void
    {
        $policy = new RetryPolicy();

        $delays = [];
        for ($attempt = 0; $attempt < 6; ++$attempt) {
            $delays[] = $policy->nextDelay($attempt);
        }

        self::assertSame([0.5, 1.0, 2.0, 4.0, 8.0, 8.0], $delays, 'последняя пауза упирается в maxBackoff');
    }

    /**
     * Half jitter: половина задержки фиксированная, половина случайная — повторы
     * не собираются в синхронные волны и не улетают в тот же перегруженный сервер.
     */
    public function testJitterStaysInHalfWindow(): void
    {
        $policy = new RetryPolicy();

        foreach ([0.0, 0.5, 0.999] as $value) {
            $delay = $policy->nextDelay(0, null, static fn () => $value);
            self::assertGreaterThanOrEqual(0.25, $delay);
            self::assertLessThanOrEqual(0.5, $delay);
        }
    }

    public function testRefusesTooLongRetryAfter(): void
    {
        $policy = new RetryPolicy();

        self::assertNull($policy->nextDelay(0, 3600.0), 'SDK согласился ждать час по подсказке сервера');
        self::assertSame(30.0, $policy->nextDelay(0, 30.0));
    }

    public function testRejectsInvalidSettings(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RetryPolicy(maxRetries: -1);
    }

    public function testCustomRetryableStatuses(): void
    {
        $policy = new RetryPolicy(retryableStatuses: [418]);

        self::assertTrue($policy->shouldRetryStatus(418, true));
        self::assertFalse($policy->shouldRetryStatus(503, true));
    }
}
