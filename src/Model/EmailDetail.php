<?php

declare(strict_types=1);

namespace MailInfra\Model;

use MailInfra\Enum\BounceCategory;
use MailInfra\Enum\BounceType;
use MailInfra\Enum\DkimSigningStatus;
use MailInfra\Enum\EmailSource;
use MailInfra\Enum\EmailStatus;
use MailInfra\Enum\SuppressionReason;
use MailInfra\Internal\Cast;

/**
 * Письмо с телом, заголовками и диагностикой доставки.
 *
 * Поля повторяют схему EmailDetail из https://docs.mailinfra.ru/openapi/mailinfra-public.json;
 * совпадение состава проверяет SpecParityTest. Отдельный класс, а не
 * наследник EmailListItem: списочная и подробная формы письма живут в API
 * своей жизнью, и наследование связало бы их сигнатуры без нужды.
 */
final class EmailDetail
{
    /**
     * @param list<string> $toEmails
     * @param list<string>|null $ccEmails
     * @param list<string>|null $bccEmails
     * @param list<string>|null $replyTo
     * @param array<string, string> $headers
     * @param list<string> $tags
     * @param array<string, string> $metadata Пары «ключ — значение», переданные
     *                                       при отправке; пусто, если их не было.
     * @param \DateTimeImmutable|null $scheduledAt На когда запланирована отправка;
     *                                             null, если письмо уходило сразу.
     * @param \DateTimeImmutable|null $expiresAt Когда истекает срок жизни письма
     *                                           (`ttl`); null, если срок не задан.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $projectId,
        public readonly string $fromEmail,
        public readonly ?string $fromName,
        public readonly array $toEmails,
        public readonly ?array $ccEmails,
        public readonly ?array $bccEmails,
        public readonly ?array $replyTo,
        public readonly string $subject,
        public readonly ?string $htmlBody,
        public readonly ?string $textBody,
        public readonly array $headers,
        public readonly EmailStatus $status,
        public readonly EmailSource $source,
        public readonly array $tags,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $sentAt,
        public readonly ?\DateTimeImmutable $deliveredAt,
        public readonly ?\DateTimeImmutable $failedAt,
        public readonly ?string $messageId,
        public readonly ?BounceType $bounceType,
        public readonly ?BounceCategory $bounceCategory,
        public readonly ?SuppressionReason $suppressionReason,
        public readonly ?int $finalSmtpCode,
        public readonly ?string $finalSmtpEnhanced,
        public readonly ?string $finalSmtpText,
        public readonly ?string $spfDomain,
        public readonly ?bool $spfAligned,
        public readonly ?bool $spfAuthorizedIp,
        public readonly ?string $dkimD,
        public readonly ?string $dkimSelector,
        public readonly ?bool $dkimAligned,
        public readonly ?DkimSigningStatus $dkimSigningStatus,
        public readonly ?bool $dmarcAligned,
        public readonly array $metadata = [],
        public readonly ?\DateTimeImmutable $scheduledAt = null,
        public readonly ?\DateTimeImmutable $expiresAt = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Cast::string($data, 'id'),
            projectId: Cast::string($data, 'project_id'),
            fromEmail: Cast::string($data, 'from_email'),
            fromName: Cast::nullableString($data, 'from_name'),
            toEmails: Cast::stringList($data, 'to_emails'),
            ccEmails: Cast::nullableStringList($data, 'cc_emails'),
            bccEmails: Cast::nullableStringList($data, 'bcc_emails'),
            replyTo: Cast::nullableStringList($data, 'reply_to'),
            subject: Cast::string($data, 'subject'),
            htmlBody: Cast::nullableString($data, 'html_body'),
            textBody: Cast::nullableString($data, 'text_body'),
            headers: Cast::stringMap($data, 'headers'),
            status: Cast::enum($data, 'status', EmailStatus::class),
            source: Cast::enum($data, 'source', EmailSource::class),
            tags: Cast::stringList($data, 'tags'),
            createdAt: Cast::dateTime($data, 'created_at'),
            sentAt: Cast::nullableDateTime($data, 'sent_at'),
            deliveredAt: Cast::nullableDateTime($data, 'delivered_at'),
            failedAt: Cast::nullableDateTime($data, 'failed_at'),
            messageId: Cast::nullableString($data, 'message_id'),
            bounceType: Cast::nullableEnum($data, 'bounce_type', BounceType::class),
            bounceCategory: Cast::nullableEnum($data, 'bounce_category', BounceCategory::class),
            suppressionReason: Cast::nullableEnum($data, 'suppression_reason', SuppressionReason::class),
            finalSmtpCode: Cast::nullableInt($data, 'final_smtp_code'),
            finalSmtpEnhanced: Cast::nullableString($data, 'final_smtp_enhanced'),
            finalSmtpText: Cast::nullableString($data, 'final_smtp_text'),
            spfDomain: Cast::nullableString($data, 'spf_domain'),
            spfAligned: Cast::nullableBool($data, 'spf_aligned'),
            spfAuthorizedIp: Cast::nullableBool($data, 'spf_authorized_ip'),
            dkimD: Cast::nullableString($data, 'dkim_d'),
            dkimSelector: Cast::nullableString($data, 'dkim_selector'),
            dkimAligned: Cast::nullableBool($data, 'dkim_aligned'),
            dkimSigningStatus: Cast::nullableEnum($data, 'dkim_signing_status', DkimSigningStatus::class),
            dmarcAligned: Cast::nullableBool($data, 'dmarc_aligned'),
            metadata: Cast::stringMap($data, 'metadata'),
            scheduledAt: Cast::nullableDateTime($data, 'scheduled_at'),
            expiresAt: Cast::nullableDateTime($data, 'expires_at'),
        );
    }
}
