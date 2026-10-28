<?php

declare(strict_types=1);

namespace MailInfra\Exception;

/**
 * Сборка исключения из ответа API.
 *
 * Классы выбираются так же, как в Python и Node SDK: сначала по конкретному
 * `error.code`, и только если он неизвестен — по HTTP-статусу. Благодаря этому
 * новый код на бэкенде не роняет старый SDK: он просто приходит как ошибка
 * своей категории.
 *
 * @internal
 */
final class ErrorFactory
{
    /**
     * Категории по HTTP-статусу.
     *
     * @var array<int, class-string<MailInfraException>>
     */
    private const STATUS_EXCEPTIONS = [
        400 => BadRequestException::class,
        401 => AuthenticationException::class,
        403 => PermissionDeniedException::class,
        404 => NotFoundException::class,
        409 => ConflictException::class,
        422 => UnprocessableException::class,
        429 => RateLimitException::class,
    ];

    /**
     * Конкретные причины по `error.code`.
     *
     * Источник истины — места `raise` в app.services.{email_service,
     * scheduled_email_service, template_service, idempotency, rate_limit} и
     * app.api.api_auth: только они говорят, какой код реально выбрасывается и
     * с каким статусом.
     *
     * @var array<string, class-string<MailInfraException>>
     */
    private const CODE_EXCEPTIONS = [
        'all_recipients_suppressed' => AllRecipientsSuppressedException::class,
        'domain_not_attached' => DomainNotAttachedException::class,
        'domain_not_verified' => DomainNotVerifiedException::class,
        'email_not_scheduled' => EmailNotScheduledException::class,
        'idempotency_in_flight' => IdempotencyInFlightException::class,
        'ip_not_allowed' => IpNotAllowedException::class,
        'legal_documents_not_accepted' => LegalDocumentsNotAcceptedException::class,
        'reserved_email_header' => ReservedEmailHeaderException::class,
        'rps_exceeded' => RateLimitException::class,
        'sandbox_recipient_not_allowed' => SandboxRecipientNotAllowedException::class,
        'sandbox_trial_exhausted' => SandboxTrialExhaustedException::class,
        'send_at_invalid' => SendAtInvalidException::class,
        'send_at_too_far' => SendAtTooFarException::class,
        'template_render_error' => TemplateRenderException::class,
        'template_variable_missing' => TemplateVariableMissingException::class,
    ];

    /**
     * Собирает исключение из неуспешного ответа API.
     *
     * Устойчиво к не-JSON телу и к ответу не по контракту: в этом случае
     * берётся класс по статусу и общее сообщение — сеть и прокси умеют
     * возвращать HTML.
     *
     * @param mixed $body Разобранное тело ответа (обычно array) или сырая строка.
     * @param int|null $retryAfter Разобранный `Retry-After` в секундах, если был.
     */
    public static function fromResponse(
        int $statusCode,
        mixed $body,
        ?string $requestId,
        ?int $retryAfter = null,
    ): MailInfraException {
        $code = 'unknown_error';
        $message = "Запрос завершился со статусом {$statusCode}";
        $details = [];

        if (\is_array($body)) {
            $envelope = $body['error'] ?? null;
            if (\is_array($envelope)) {
                if (\is_string($envelope['code'] ?? null) && $envelope['code'] !== '') {
                    $code = $envelope['code'];
                }
                if (\is_string($envelope['message'] ?? null) && $envelope['message'] !== '') {
                    $message = $envelope['message'];
                }
                if (\is_array($envelope['details'] ?? null)) {
                    $details = $envelope['details'];
                }
            }
            if ($requestId === null && \is_string($body['request_id'] ?? null)) {
                $requestId = $body['request_id'];
            }
        }

        // `details.code` уточняет причину внутри статуса (например, 429 →
        // `rps_exceeded`), поэтому он приоритетнее верхнеуровневого кода.
        $detailCode = $details['code'] ?? null;
        if (\is_string($detailCode) && isset(self::CODE_EXCEPTIONS[$detailCode])) {
            $code = $detailCode;
        }

        $class = self::CODE_EXCEPTIONS[$code] ?? self::statusException($statusCode);

        if (is_a($class, RateLimitException::class, true)) {
            return new $class($message, $statusCode, $code, $details, $requestId, $retryAfter);
        }

        return new $class($message, $statusCode, $code, $details, $requestId);
    }

    /**
     * Возвращает класс исключения по HTTP-статусу.
     *
     * @return class-string<MailInfraException>
     */
    private static function statusException(int $statusCode): string
    {
        if (isset(self::STATUS_EXCEPTIONS[$statusCode])) {
            return self::STATUS_EXCEPTIONS[$statusCode];
        }

        return $statusCode >= 500 ? ServerException::class : MailInfraException::class;
    }
}
