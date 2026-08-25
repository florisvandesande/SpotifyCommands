<?php

declare(strict_types=1);

final class HttpResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        try {
            $decoded = json_decode($this->body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new AppException('spotify_request_failed', 'Spotify returned invalid JSON.', 502, previous: $error);
        }

        if (!is_array($decoded)) {
            throw new AppException('spotify_request_failed', 'Spotify returned an unexpected response.', 502);
        }

        return $decoded;
    }
}

interface HttpClient
{
    /**
     * @param array<string, string> $headers
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse;
}

final class CurlHttpClient implements HttpClient
{
    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new AppException('spotify_request_failed', 'The HTTP client could not be initialized.', 502);
        }

        $response_headers = [];
        $header_lines = [];
        foreach ($headers as $name => $value) {
            $header_lines[] = $name . ': ' . $value;
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $header_lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$response_headers): int {
                $length = strlen($line);
                $separator = strpos($line, ':');
                if ($separator !== false) {
                    $name = strtolower(trim(substr($line, 0, $separator)));
                    $response_headers[$name] = trim(substr($line, $separator + 1));
                }
                return $length;
            },
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $response_body = curl_exec($handle);
        if ($response_body === false) {
            $message = curl_error($handle);
            curl_close($handle);
            throw new AppException('spotify_request_failed', 'Spotify could not be reached: ' . $message, 502);
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return new HttpResponse($status, (string) $response_body, $response_headers);
    }
}
