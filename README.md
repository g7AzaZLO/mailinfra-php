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
| Идемпотентность | На каждую отправку и каждый батч ставится `Idempotency-Key`, и он **переиспользуется при повторах** — обрыв сети не превращается в два письма |
| Повторы | `429`, `408/425`, `5xx` и сетевые сбои повторяются с экспоненциальным backoff и half-jitter |
| Rate limit | `Retry-After` уважается как есть; слишком долгий (>60 с) не блокирует поток, а приходит как `RateLimitException::getRetryAfter()` |
| Типизированные ошибки | `DomainNotVerifiedException`, `RateLimitException`, `UnprocessableException::getFields()` и ещё ~20 классов |
| Пагинация | `listAll()` сам идёт по курсору и запрашивает страницы по мере обхода |
| Проверка до сети | Лимиты получателей, тегов, вложений, `metadata` и `ttl`, часовой пояс `sendAt` |
| Типы | Enum'ы для статусов и причин отказа, readonly-модели для ответов |
| Вебхуки | `Webhooks::constructEvent()` — проверка HMAC-подписи (в том числе во время ротации секрета) и разбор события |

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

### Inline-картинки

Вложение с `contentId` встраивается в письмо, а не прикладывается файлом: в HTML на
него ссылаются как `cid:<contentId>`.

```php
$client->emails->send(
    from: 'hello@mail.example.ru',
    to: 'user@example.com',
    subject: 'Новая коллекция',
    html: '<p><img src="cid:logo" alt="Логотип"></p>',
    attachments: [
        Attachment::fromFile('/var/www/logo.png', contentType: 'image/png', contentId: 'logo'),
    ],
);
```

### Метаданные письма

```php
$client->emails->send(
    from: 'billing@mail.example.ru',
    to: 'user@example.com',
    subject: 'Счёт A-1024',
    text: 'Счёт во вложении.',
    metadata: ['order_id' => 'A-1024', 'user_id' => '7'],
);
```

`metadata` — ваши пары «ключ — значение»: они возвращаются в `list()` и `get()`
(`$email->metadata`) и в каждом вебхуке письма (`$event->metadata()`), так что событие
легко связать с заказом в своей системе. До 20 ключей; ключ — до 40 символов из
латиницы, цифр, `_`, `-` и `.`; значение — строка до 500 символов; весь объект — до
4096 байт JSON. Лимиты проверяются до сети.

### Отложенная отправка

```php
use MailInfra\Enum\EmailStatus;

$result = $client->emails->send(
    from: 'hello@mail.example.ru',
    to: 'user@example.com',
    subject: 'Напоминание об оплате',
    html: '<p>Заказ A-1024 ждёт оплаты</p>',
    sendAt: new DateTimeImmutable('+1 day'),
);
// $result->status === EmailStatus::Scheduled

// Пользователь оплатил — напоминание больше не нужно.
$client->emails->cancel($result->id);

// Или перенести на другое время.
$client->emails->reschedule($result->id, sendAt: '2026-11-02T09:00:00+03:00');
```

`sendAt` — не дальше 7 дней вперёд; время в прошлом означает «отправить сразу».
`DateTimeInterface` уходит в API в UTC, строка — как есть, и в ней обязателен
часовой пояс: строку вида `date('Y-m-d H:i:s')` SDK отклонит до сети, иначе API не
понял бы, чьё это время. Отменить и перенести можно только письмо в статусе
`SCHEDULED` — иначе придёт `EmailNotScheduledException`, а дальше 7 дней —
`SendAtTooFarException`. Для отмены и переноса нужно то же право ключа, что для
отправки. После `5xx` и сбоя сети SDK повторяет `cancel()` сам: дважды письмо не
отменится, а если первая попытка всё-таки дошла, повтор получит
`EmailNotScheduledException` с `CANCELED` в `getDetails()['status']` — это тоже успех.

### Срок жизни письма

```php
$client->emails->send(
    from: 'auth@mail.example.ru',
    to: 'user@example.com',
    subject: 'Код входа',
    text: 'Ваш код: 4821',
    ttl: 300, // через 5 минут код уже не нужен
);
```

`ttl` — от 60 секунд до суток, отсчёт от `sendAt` или от приёма письма. Не дошедшее
за это время письмо больше не отправляется и не повторяется: статус `FAILED` с
причиной `expired` или отказ с категорией `BounceCategory::DeliveryExpired`.

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

Ключи элемента — те же, что у `send()`, включая `metadata`, `sendAt` и `ttl`. До 100
писем за раз. `Idempotency-Key` у батча один на весь запрос, и SDK ставит его сам, как
у `send()`: бэкенд выводит из него ключ каждого письма, поэтому повтор после `5xx`,
таймаута или обрыва соединения не дублирует уже принятые письма, а отклонённые пробует
принять заново.

```php
$batch = $client->emails->sendBatch($emails, idempotencyKey: 'digest-2026-11-01');

if ($batch->replayed) {
    // все принятые письма были приняты прежним запросом с этим ключом
}
```

Свой ключ батча — до 252 символов: бэкенд дописывает к нему номер письма. Без ключа
(`withoutIdempotencyKey: true`) письма батча коммитятся по одному без защиты от дублей,
и SDK повторяет такой батч только на `429` — лимитёр отказывает до приёма писем.

## Чтение

```php
use MailInfra\Enum\EmailStatus;

$email = $client->emails->get($emailId);

$filters = [
    'status' => EmailStatus::Bounced,
    'tag' => 'order',
    'from' => new DateTimeImmutable('-24 hours'),
    'limit' => 50,
];
$page = $client->emails->list(...$filters);
foreach ($page->data as $item) {
    // …
}
if ($page->hasMore) {
    $next = $client->emails->list(...$filters, cursor: $page->nextCursor);
}

$events = $client->emails->events($emailId);
```

`from` и `to` в `list()` — это интервал по времени создания письма, а не адреса; адреса
фильтруются через `fromEmail` и `toEmail`. Время уходит в API в UTC.

`list()` возвращает одну страницу `EmailListPage`, новые письма сначала. Следующая —
тот же вызов с теми же фильтрами и `cursor: $page->nextCursor`: курсор помнит место в
выборке, но не фильтры. Обойти все страницы без ручной работы с курсором —
`listAll()`:

```php
foreach ($client->emails->listAll(status: EmailStatus::Bounced, limit: 100) as $email) {
    suppressLocally($email->toEmails);
}
```

`listAll()` — генератор: следующая страница запрашивается, когда до неё дошёл
`foreach`, поэтому `break` не тянет лишних страниц, а ошибка API всплывает на итерации,
а не при вызове. `limit` здесь — размер страницы, а не потолок обхода.

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
  `IdempotencyInFlightException`, `LegalDocumentsNotAcceptedException`,
  `EmailNotScheduledException`, `SendAtTooFarException`, `SendAtInvalidException`,
  `IpNotAllowedException` (ключ использован с IP-адреса вне его белого списка).

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
    'email.bounced' => handleBounce($event->emailId(), $event->metadata()['order_id'] ?? null),
    'email.delivered' => handleDelivered($event->emailId()),
    'email.canceled' => handleCanceled($event->emailId()),
    default => null,
};
```

Тело обязательно брать **сырым** (`php://input`), а не из `$_POST`: пересериализованный
JSON даст другие байты и подпись не сойдётся. Подписи старше 5 минут отклоняются —
окно меняется аргументом `maxAgeSeconds`, `0` отключает проверку.

Во время ротации секрета MailInfra подписывает доставку дважды — новым секретом и
прежним: `t=…,v1=<новым>,v1=<прежним>`. SDK сверяет каждую подпись и принимает
доставку, если совпала любая, поэтому переключить `MAILINFRA_WEBHOOK_SECRET` на новый
секрет можно в любой момент окна ротации — доставки не начнут отклоняться.

`$event->metadata()` — `metadata`, переданное при отправке письма. Отложенные письма
дают события `email.scheduled` (запланировано или перенесено) и `email.canceled`.

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
