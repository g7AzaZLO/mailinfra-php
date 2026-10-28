<?php

declare(strict_types=1);

namespace MailInfra\Model;

use MailInfra\Exception\WebhookSignatureException;

/**
 * Разобранное событие вебхука.
 */
final class WebhookEvent
{
    /**
     * @param string $id Идентификатор события: `evt_<uuid>` у событий письма,
     *                   `evt_deliverability_<hex>` у `deliverability.*`,
     *                   `evt_test_ping` у тестовой отправки.
     * @param string $type Тип: `email.<событие>` у событий письма (например
     *                     `email.bounced`, `email.scheduled` при планировании и
     *                     переносе, `email.canceled` при отмене),
     *                     `deliverability.<инцидент>` как есть
     *                     (например `deliverability.dns_degraded`), `test.ping`
     *                     у тестовой отправки из дашборда.
     * @param \DateTimeImmutable $createdAt Момент события на стороне MailInfra.
     * @param array<string, mixed> $data Полезная нагрузка. У событий письма
     *                                   `data['email']` — письмо (с `metadata`,
     *                                   переданным при отправке), `data['event']` —
     *                                   специфика события (MX, IP, bounce-детали,
     *                                   URL клика). У `deliverability.*` письма нет:
     *                                   `domain_id`, `domain`, `severity`, `title`,
     *                                   `details` инцидента.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly \DateTimeImmutable $createdAt,
        public readonly array $data,
    ) {
    }

    /**
     * Письмо, к которому относится событие; null для событий без письма
     * (`deliverability.*`, `test.ping`).
     *
     * @return array<string, mixed>|null
     */
    public function email(): ?array
    {
        $email = $this->data['email'] ?? null;

        return \is_array($email) ? $email : null;
    }

    /**
     * Идентификатор письма; null, если события письма нет.
     */
    public function emailId(): ?string
    {
        $id = $this->email()['id'] ?? null;

        return \is_string($id) ? $id : null;
    }

    /**
     * Пары «ключ — значение», переданные при отправке письма: по ним событие
     * связывается с заказом или пользователем в вашей системе. Пусто, если
     * письма нет или metadata не задавали.
     *
     * @return array<string, string>
     */
    public function metadata(): array
    {
        $metadata = $this->email()['metadata'] ?? null;
        if (!\is_array($metadata)) {
            return [];
        }

        // Значения по контракту строковые; чужое тут — не повод ронять
        // обработчик вебхука, такое значение просто не попадает в результат.
        return array_filter($metadata, static fn ($value) => \is_string($value));
    }

    /**
     * Тестовое ли это событие («Отправить тест» в дашборде).
     */
    public function isTest(): bool
    {
        return $this->type === 'test.ping';
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws WebhookSignatureException Если событие пришло не по контракту.
     *                                   Тип тот же, что у отказа подписи:
     *                                   обработчик, написанный по документации,
     *                                   ловит один catch, а не два.
     */
    public static function fromArray(array $payload): self
    {
        $id = $payload['id'] ?? null;
        $type = $payload['type'] ?? null;
        $createdAt = $payload['created_at'] ?? null;

        if (!\is_string($id) || $id === '' || !\is_string($type) || $type === '' || !\is_string($createdAt)) {
            throw new WebhookSignatureException(
                'Событие вебхука не по контракту: нужны непустые id, type и created_at',
                WebhookSignatureException::REASON_INVALID_PAYLOAD,
            );
        }

        try {
            $moment = new \DateTimeImmutable($createdAt);
        } catch (\Exception $exception) {
            throw new WebhookSignatureException(
                "Событие вебхука не по контракту: created_at не разобран ({$createdAt})",
                WebhookSignatureException::REASON_INVALID_PAYLOAD,
                $exception,
            );
        }

        $data = $payload['data'] ?? [];

        return new self($id, $type, $moment, \is_array($data) ? $data : []);
    }
}
