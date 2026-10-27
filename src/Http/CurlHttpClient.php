<?php

declare(strict_types=1);

namespace MailInfra\Http;

/**
 * Транспорт по умолчанию — на ext-curl.
 *
 * Без внешних зависимостей: SDK ставится в любой проект, включая legacy-сборки
 * Bitrix и WordPress, где Guzzle может конфликтовать по версии с уже
 * подключённым.
 */
final class CurlHttpClient implements HttpClientInterface
{
    /**
     * @param array<int, mixed> $curlOptions Дополнительные опции cURL: прокси,
     *                                       свой CA-бандл, привязка к интерфейсу.
     *                                       Применяются последними и перекрывают
     *                                       умолчания.
     */
    public function __construct(private readonly array $curlOptions = [])
    {
    }

    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        ?float $timeout,
    ): HttpResponse {
        $handle = curl_init();
        if ($handle === false) {
            throw new TransportException('Не удалось инициализировать cURL');
        }

        $responseHeaders = [];
        $options = [
            \CURLOPT_URL => $url,
            \CURLOPT_CUSTOMREQUEST => $method,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_HTTPHEADER => self::formatHeaders($headers),
            \CURLOPT_HEADERFUNCTION => static function ($_handle, string $line) use (&$responseHeaders): int {
                $length = \strlen($line);
                $parts = explode(':', $line, 2);
                if (\count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return $length;
            },
        ];

        if ($body !== null) {
            $options[\CURLOPT_POSTFIELDS] = $body;
        }
        if ($timeout !== null && $timeout > 0) {
            // Таймаут в миллисекундах: секундная гранулярность CURLOPT_TIMEOUT
            // округлила бы 0.5 с до нуля, то есть «без таймаута».
            $options[\CURLOPT_TIMEOUT_MS] = (int) round($timeout * 1000);
            $options[\CURLOPT_CONNECTTIMEOUT_MS] = (int) round($timeout * 1000);
        }

        foreach ($this->curlOptions as $option => $value) {
            $options[$option] = $value;
        }
        curl_setopt_array($handle, $options);

        $raw = curl_exec($handle);
        $errorNumber = curl_errno($handle);
        $errorMessage = curl_error($handle);
        $statusCode = (int) curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($raw === false || $errorNumber !== 0) {
            throw new TransportException(
                "Запрос к API MailInfra не выполнен: {$errorMessage}",
                \in_array($errorNumber, [\CURLE_OPERATION_TIMEOUTED, 28], true),
            );
        }

        return new HttpResponse($statusCode, $responseHeaders, \is_string($raw) ? $raw : '');
    }

    /**
     * @param array<string, string> $headers
     *
     * @return list<string>
     */
    private static function formatHeaders(array $headers): array
    {
        $formatted = [];
        foreach ($headers as $name => $value) {
            $formatted[] = "{$name}: {$value}";
        }

        return $formatted;
    }
}
