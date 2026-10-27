<?php

declare(strict_types=1);

namespace MailInfra\Tests;

use MailInfra\MailInfra;

/**
 * Клиент, у которого повторы не занимают реального времени, а backoff
 * детерминирован.
 *
 * Оба шва объявлены в MailInfra как protected именно ради этого: политика
 * повторов проверяется на настоящем цикле запросов, а не на моке.
 */
final class TestableMailInfra extends MailInfra
{
    /** @var list<float> Паузы, которые клиент собирался выдержать. */
    public array $slept = [];

    protected function sleepSeconds(float $seconds): void
    {
        $this->slept[] = $seconds;
    }

    /**
     * Half jitter при множителе 1.0 даёт ровно базовую задержку — так в тестах
     * видно саму последовательность backoff, а не случайную её половину.
     */
    protected function jitter(): float
    {
        return 1.0;
    }
}
