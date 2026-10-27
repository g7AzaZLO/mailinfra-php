<?php

declare(strict_types=1);

namespace MailInfra\Http;

/**
 * Транспорт, которым клиент ходит в API.
 *
 * По умолчанию используется CurlHttpClient — без внешних зависимостей, чтобы
 * SDK ставился в любой проект, включая legacy-окружения Bitrix и WordPress.
 * Если в проекте уже есть Guzzle, Symfony HttpClient или любой PSR-18 клиент,
 * напишите десяток строк адаптера и передайте его в MailInfra::__construct:
 * пул соединений, прокси и логирование останутся вашими.
 */
interface HttpClientInterface
{
    /**
     * Выполняет один HTTP-запрос.
     *
     * Повторы, backoff и разбор ошибок — забота клиента, а не транспорта:
     * здесь нужен ровно один запрос, без самодеятельности.
     *
     * @param array<string, string> $headers
     * @param float|null $timeout Таймаут запроса в секундах; null — не ставить свой.
     *
     * @throws TransportException Если ответа не было: DNS, TCP, TLS, таймаут, обрыв.
     */
    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        ?float $timeout,
    ): HttpResponse;
}
