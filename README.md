# MailInfra SDK для PHP

Официальный клиент [MailInfra](https://mailinfra.ru) — email API для разработчиков.

```bash
composer require mailinfra/mailinfra
```

```php
use MailInfra\MailInfra;

$client = new MailInfra(); // ключ из MAILINFRA_API_KEY

$result = $client->emails->send(
    from: 'hello@mail.example.ru',
    to: 'user@example.com',
    subject: 'Добро пожаловать!',
    html: '<p>Привет!</p>',
);
echo $result->id, ' ', $result->status->value;
```

Требования: PHP 8.1+, расширения `curl`, `json`, `mbstring`. Внешних зависимостей нет —
SDK не конфликтует с версией Guzzle, которая уже стоит в проекте (актуально для Bitrix,
WordPress и Laravel-сборок постарше).

## Что клиент делает за вас

| Возможность | Подробности |
|---|---|
| Идемпотентность | На каждую отправку ставится `Idempotency-Key`, и он **переиспользуется при повторах** — обрыв сети не превращается в два письма |
| Повторы | `429`, `408/425`, `5xx` и сетевые сбои повторяются с экспоненциальным backoff и half-jitter |
| Rate limit | `Retry-After` уважается как есть; слишком долгий (>60 с) не блокирует поток, а приходит как `RateLimitException::getRetryAfter()` |
| Типизированные ошибки | `DomainNotVerifiedException`, `RateLimitException`, `UnprocessableException::getFields()` и ещё ~20 классов |
| Типы | Enum'ы для статусов и причин отказа, readonly-модели для ответов |
| Вебхуки | `Webhooks::constructEvent()` — проверка HMAC-подписи и разбор события |

## Аутентификация

```php
new MailInfra(apiKey: 'mi_live_…');                          // явно
new MailInfra();                                              // из MAILINFRA_API_KEY
new MailInfra(requireEnvironment: Environment::Live);         // упасть на тестовом ключе
```

`mi_live_…` отправляет письма по-настоящему. `mi_test_…` — sandbox: письмо сохраняется
со статусом `SIMULATED`, не доставляется и не расходует месячный объём плана.
Окружение доступно как `$client->environment`.

База API меняется через `baseUrl` или переменную `MAILINFRA_BASE_URL`.

## Отправка

Все методы принимают именованные аргументы — порядок запоминать не нужно.

### По шаблону

```php
$result = $client->emails->send(
    from: 'hello@mail.example.ru',
    to: 'user@example.com',
    template: 'order-confirmed',
    variables: ['name' => 'Аня', 'order' => 'A-1024'],
);
```

### С вложением

```php
use MailInfra\Model\Attachment;

$client->emails->send(
    from: 'billing@mail.example.ru',
    to: 'user@example.com',
    subject: 'Счёт',
    text: 'Счёт во вложении.',
    attachments: [
        Attachment::fromFile('/var/invoices/A-1024.pdf', contentType: 'application/pdf'),
    ],
);
```

`Attachment::fromContent()` возьмёт содержимое из строки, `Attachment::fromBase64()` —
готовый base64.

### Свой ключ идемпотентности

```php
$result = $client->emails->send(
    from: 'hello@mail.example.ru',
    to: 'user@example.com',
    subject: 'Заказ A-1024 оплачен',
    html: '<p>Спасибо!</p>',
    idempotencyKey: 'order-A-1024-paid', // повтор вернёт то же письмо
);

if ($result->replayed) {
    // письмо уже было отправлено раньше, дубля нет
}
```

Без `idempotencyKey` клиент генерирует uuid4 сам — на каждый вызов новый. Отправить
вообще без ключа можно через `withoutIdempotencyKey: true`, но тогда бэкенду нечем
распознать повтор, и SDK перестанет ретраить этот запрос после `5xx` и таймаутов.
Бэкенд хранит ключ 24 часа: повтор с тем же ключом позже создаст новое письмо.

### Пакетная отправка

```php
$batch = $client->emails->sendBatch([
    ['from' => 'hello@mail.example.ru', 'to' => 'a@example.com', 'subject' => 'Раз', 'text' => '1'],
    ['from' => 'hello@mail.example.ru', 'to' => 'b@example.com', 'subject' => 'Два', 'text' => '2'],
]);

// Батч возвращает 202, даже если часть писем не принята.
foreach ($batch->failed() as $item) {
    error_log("письмо #{$item->index} отклонено: {$item->error->code}");
}
```

Ключи элемента — те же, что у `send()`. До 100 писем за раз. `Idempotency-Key` этот
эндпоинт не принимает: бэкенд коммитит письма батча по одному, поэтому SDK не повторяет
его после `5xx`, таймаута и обрыва соединения. Повторяется только `429` — лимитёр
отказывает до приёма писем.

## Чтение

```php
use MailInfra\Enum\EmailStatus;

$email = $client->emails->get($emailId);

$emails = $client->emails->list(
    status: EmailStatus::Bounced,
    tag: 'order',
    from: new DateTimeImmutable('-24 hours'),
    limit: 50,
);

$events = $client->emails->events($emailId);
```

`from` и `to` в `list()` — это интервал по времени создания письма, а не адреса; адреса
фильтруются через `fromEmail` и `toEmail`. Время уходит в API в UTC.

## Ошибки

```php
use MailInfra\Exception\DomainNotVerifiedException;
use MailInfra\Exception\MailInfraException;
use MailInfra\Exception\RateLimitException;
use MailInfra\Exception\UnprocessableException;

try {
    $client->emails->send(...);
} catch (DomainNotVerifiedException $e) {
    // домен отправителя ещё не подтверждён
} catch (RateLimitException $e) {
    // Либо лимит сбросится нескоро, либо повторы SDK кончились: отложите
    // письмо на getRetryAfter() секунд, а не усыпляйте процесс.
    // $queue->releaseLater — ваш механизм отложенных задач.
    $queue->releaseLater($message, $e->getRetryAfter() ?? 3600);
} catch (UnprocessableException $e) {
    // вся категория 422 разом
    foreach ($e->getFields() as $field) {
        error_log("{$field['field']}: {$field['message']}");
    }
} catch (MailInfraException $e) {
    error_log("{$e->getMessage()} (request_id={$e->getRequestId()})");
}
```

Три уровня ловли:

- `MailInfraException` — всё, что вернул API, вместе с `getRequestId()`;
- категория статуса: `BadRequestException`, `AuthenticationException`,
  `PermissionDeniedException`, `NotFoundException`, `ConflictException`,
  `UnprocessableException`, `RateLimitException`, `ServerException`,
  `ApiConnectionException`, `ApiTimeoutException`;
- конкретная причина: `DomainNotVerifiedException`, `DomainNotAttachedException`,
  `AllRecipientsSuppressedException`, `TemplateRenderException`,
  `TemplateVariableMissingException`, `ReservedEmailHeaderException`,
  `SandboxRecipientNotAllowedException`, `SandboxTrialExhaustedException`,
  `IdempotencyInFlightException`, `LegalDocumentsNotAcceptedException`.

Конкретная причина всегда наследует свою категорию: `catch (UnprocessableException $e)`
поймает и `DomainNotVerifiedException`. Порядок `catch` важен — конкретные классы идут
раньше базовых. Неизвестный код с бэкенда деградирует до класса по статусу, поэтому
новый код не ломает старый SDK.

Ошибки параметров вызова — `InvalidRequestException`: запрос до сети не доходит.

## Настройка повторов

```php
use MailInfra\RetryPolicy;

$client = new MailInfra(
    retryPolicy: new RetryPolicy(
        maxRetries: 4,
        initialBackoff: 0.2,
        maxBackoff: 5.0,
        maxRetryAfter: 30.0, // дольше — не ждём, отдаём RateLimitException
    ),
);
```

`maxRetries: 0` отключает повторы. Таймаут одного запроса — аргумент `timeout`
(по умолчанию 30 с), `null` оставляет управление временем вашему транспорту.

## Свой HTTP-транспорт

По умолчанию используется cURL. Если в проекте уже есть Guzzle, Symfony HttpClient или
любой PSR-18 клиент — реализуйте `MailInfra\Http\HttpClientInterface` и передайте его:

```php
$client = new MailInfra(httpClient: new MyGuzzleAdapter($guzzle));
```

Прокси и свой CA-бандл можно задать и без адаптера:

```php
use MailInfra\Http\CurlHttpClient;

$client = new MailInfra(httpClient: new CurlHttpClient([
    CURLOPT_PROXY => 'http://proxy.internal:3128',
]));
```

## Вебхуки

```php
use MailInfra\Exception\WebhookSignatureException;
use MailInfra\Webhooks;

try {
    $event = Webhooks::constructEvent(
        body: file_get_contents('php://input'),
        secret: getenv('MAILINFRA_WEBHOOK_SECRET') ?: '',
        signatureHeader: $_SERVER['HTTP_X_MAILINFRA_SIGNATURE'] ?? '',
    );
} catch (WebhookSignatureException $e) {
    // invalid_secret, malformed_header, too_old, signature_mismatch,
    // invalid_json, invalid_payload
    error_log('подпись отклонена: ' . $e->getReason());
    http_response_code(400);
    exit;
}

match ($event->type) {
    'email.bounced' => handleBounce($event->emailId()),
    'email.delivered' => handleDelivered($event->emailId()),
    default => null,
};
```

Тело обязательно брать **сырым** (`php://input`), а не из `$_POST`: пересериализованный
JSON даст другие байты и подпись не сойдётся. Подписи старше 5 минут отклоняются —
окно меняется аргументом `maxAgeSeconds`, `0` отключает проверку.

## Что пока не покрыто

v1 покрывает публичный API писем (`/v1/emails*`) — всё, что доступно по API-ключу
проекта. Управление доменами, шаблонами, списком подавления, вебхуками и ключами — это
Management API на сессионном токене пользователя; для машинной интеграции у него пока
нет подходящего credential, поэтому в SDK этих ресурсов нет. Следите за
[CHANGELOG](CHANGELOG.md).

## Ссылки

- Документация: <https://docs.mailinfra.ru>
- Быстрый старт для PHP: <https://docs.mailinfra.ru/sdk/php>
- Формат ошибок: <https://docs.mailinfra.ru/errors>
- Вебхуки: <https://docs.mailinfra.ru/webhooks/signature>
- OpenAPI-спека: <https://docs.mailinfra.ru/openapi/mailinfra-public.json>
- Исходный код и issues: <https://github.com/g7AzaZLO/mailinfra-php>. Репозиторий
  обновляется при каждом релизе SDK; ошибки и предложения — в issues, pull request'ы
  не принимаются: SDK разрабатывается вместе с API MailInfra.

## Лицензия

MIT
