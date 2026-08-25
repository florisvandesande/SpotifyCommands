<?php

declare(strict_types=1);

final class SpotifyClient
{
    private const API_URL = 'https://api.spotify.com/v1';
    private const AUTHORIZE_URL = 'https://accounts.spotify.com/authorize';
    private const TOKEN_URL = 'https://accounts.spotify.com/api/token';
    private const SCOPES = [
        'user-read-currently-playing',
        'playlist-read-private',
        'playlist-modify-public',
        'playlist-modify-private',
    ];

    /**
     * @param callable(int): void|null $sleeper
     */
    public function __construct(
        private readonly string $client_id,
        private readonly string $client_secret,
        private readonly string $redirect_uri,
        private readonly TokenStorage $token_storage,
        private readonly HttpClient $http_client,
        private $sleeper = null,
    ) {
        $this->sleeper ??= static fn (int $seconds): bool => sleep($seconds) === 0;
    }

    public function authorization_url(string $state): string
    {
        return self::AUTHORIZE_URL . '?' . http_build_query([
            'client_id' => $this->client_id,
            'response_type' => 'code',
            'redirect_uri' => $this->redirect_uri,
            'scope' => implode(' ', self::SCOPES),
            'state' => $state,
            'show_dialog' => 'true',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchange_authorization_code(string $code): void
    {
        $response = $this->token_request([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirect_uri,
        ]);

        $refresh_token = $response['refresh_token'] ?? null;
        if (!is_string($refresh_token) || $refresh_token === '') {
            throw new AppException('spotify_reauthorization_required', 'Spotify did not return a refresh token.', 503);
        }

        $this->save_token_response($response, $refresh_token);
    }

    /**
     * @return array{name: string, artists: array<int, string>, uri: string}
     */
    public function current_track(): array
    {
        $response = $this->api_request('GET', '/me/player/currently-playing');
        if ($response->status === 204 || trim($response->body) === '') {
            throw new AppException('nothing_playing', translate('nothing_playing'), 409);
        }

        $payload = $response->json();
        if (($payload['is_playing'] ?? false) !== true) {
            throw new AppException('nothing_playing', translate('nothing_playing'), 409);
        }

        $item = $payload['item'] ?? null;
        if (!is_array($item) || ($item['type'] ?? null) !== 'track') {
            throw new AppException('unsupported_item', translate('unsupported_item'), 422);
        }

        if (($item['is_local'] ?? false) === true || ($payload['is_local'] ?? false) === true) {
            throw new AppException('local_track', translate('local_track'), 422);
        }

        $uri = $item['uri'] ?? null;
        $name = $item['name'] ?? null;
        if (!is_string($uri) || !str_starts_with($uri, 'spotify:track:') || !is_string($name)) {
            throw new AppException('unsupported_item', translate('unsupported_item'), 422);
        }

        $artists = [];
        foreach (($item['artists'] ?? []) as $artist) {
            if (is_array($artist) && is_string($artist['name'] ?? null)) {
                $artists[] = $artist['name'];
            }
        }

        return [
            'name' => $name,
            'artists' => $artists,
            'uri' => $uri,
        ];
    }

    public function playlist_contains(string $playlist_id, string $track_uri): bool
    {
        $offset = 0;

        do {
            $response = $this->api_request('GET', '/playlists/' . rawurlencode($playlist_id) . '/items', [
                'fields' => 'items(item(uri,type)),total,limit,offset',
                'limit' => '50',
                'offset' => (string) $offset,
            ]);
            $payload = $response->json();
            $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];

            foreach ($items as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $item = $entry['item'] ?? $entry['track'] ?? null;
                if (is_array($item) && ($item['uri'] ?? null) === $track_uri) {
                    return true;
                }
            }

            $offset += count($items);
            $total = is_int($payload['total'] ?? null) ? $payload['total'] : (int) ($payload['total'] ?? 0);
        } while ($items !== [] && $offset < $total);

        return false;
    }

    public function add_track(string $playlist_id, string $track_uri): void
    {
        $this->api_request(
            'POST',
            '/playlists/' . rawurlencode($playlist_id) . '/items',
            body: ['uris' => [$track_uri]],
        );
    }

    public function check_playlist_access(string $playlist_id): int
    {
        $response = $this->api_request('GET', '/playlists/' . rawurlencode($playlist_id) . '/items', [
            'fields' => 'total',
            'limit' => '1',
        ]);
        $payload = $response->json();

        return (int) ($payload['total'] ?? 0);
    }

    public function has_tokens(): bool
    {
        return $this->token_storage->load() !== null;
    }

    /**
     * @param array<string, string> $query
     * @param array<string, mixed>|null $body
     */
    private function api_request(string $method, string $path, array $query = [], ?array $body = null): HttpResponse
    {
        $url = self::API_URL . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $encoded_body = $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $access_token = $this->access_token();
        $response = $this->request_with_rate_limit_retry($method, $url, [
            'Authorization' => 'Bearer ' . $access_token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ], $encoded_body);

        if ($response->status === 401) {
            $access_token = $this->access_token(force_refresh: true, rejected_access_token: $access_token);
            $response = $this->request_with_rate_limit_retry($method, $url, [
                'Authorization' => 'Bearer ' . $access_token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ], $encoded_body);
        }

        if ($response->status < 200 || $response->status >= 300) {
            throw $this->spotify_error($response);
        }

        return $response;
    }

    private function access_token(bool $force_refresh = false, ?string $rejected_access_token = null): string
    {
        $tokens = $this->token_storage->load();
        if ($tokens === null) {
            throw new AppException(
                'spotify_reauthorization_required',
                translate('spotify_reauthorization_required'),
                503,
            );
        }

        if (!$force_refresh && $tokens['expires_at'] > time() + 30) {
            return $tokens['access_token'];
        }

        return $this->token_storage->with_exclusive_lock(function () use ($force_refresh, $rejected_access_token): string {
            $tokens = $this->token_storage->load();
            if ($tokens === null) {
                throw new AppException(
                    'spotify_reauthorization_required',
                    translate('spotify_reauthorization_required'),
                    503,
                );
            }

            $another_request_refreshed = $force_refresh
                && $rejected_access_token !== null
                && $tokens['access_token'] !== $rejected_access_token
                && $tokens['expires_at'] > time() + 30;

            if ((!$force_refresh && $tokens['expires_at'] > time() + 30) || $another_request_refreshed) {
                return $tokens['access_token'];
            }

            return $this->refresh_access_token($tokens);
        });
    }

    /** @param array{access_token: string, refresh_token: string, expires_at: int} $tokens */
    private function refresh_access_token(array $tokens): string
    {
        $response = $this->token_request([
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokens['refresh_token'],
        ]);
        $refresh_token = $response['refresh_token'] ?? $tokens['refresh_token'];
        if (!is_string($refresh_token) || $refresh_token === '') {
            $refresh_token = $tokens['refresh_token'];
        }
        $this->save_token_response($response, $refresh_token);

        return (string) $response['access_token'];
    }

    /**
     * @param array<string, string> $parameters
     * @return array<string, mixed>
     */
    private function token_request(array $parameters): array
    {
        $response = $this->request_with_rate_limit_retry('POST', self::TOKEN_URL, [
            'Authorization' => 'Basic ' . base64_encode($this->client_id . ':' . $this->client_secret),
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query($parameters, '', '&', PHP_QUERY_RFC3986));

        if ($response->status < 200 || $response->status >= 300) {
            $payload = $response->body === '' ? [] : $response->json();
            if (($payload['error'] ?? null) === 'invalid_grant') {
                throw new AppException(
                    'spotify_reauthorization_required',
                    translate('spotify_reauthorization_required'),
                    503,
                );
            }
            throw $this->spotify_error($response);
        }

        $payload = $response->json();
        if (!is_string($payload['access_token'] ?? null) || !is_numeric($payload['expires_in'] ?? null)) {
            throw new AppException('spotify_request_failed', translate('spotify_request_failed'), 502);
        }

        return $payload;
    }

    /** @param array<string, mixed> $response */
    private function save_token_response(array $response, string $refresh_token): void
    {
        $this->token_storage->save(
            (string) $response['access_token'],
            $refresh_token,
            time() + (int) $response['expires_in'],
        );
    }

    /** @param array<string, string> $headers */
    private function request_with_rate_limit_retry(
        string $method,
        string $url,
        array $headers,
        ?string $body,
    ): HttpResponse {
        $response = $this->http_client->request($method, $url, $headers, $body);
        if ($response->status !== 429) {
            return $response;
        }

        $retry_after = filter_var($response->headers['retry-after'] ?? null, FILTER_VALIDATE_INT);
        if ($retry_after !== false && $retry_after >= 0 && $retry_after <= 10) {
            ($this->sleeper)($retry_after);
            return $this->http_client->request($method, $url, $headers, $body);
        }

        return $response;
    }

    private function spotify_error(HttpResponse $response): AppException
    {
        if ($response->status === 429) {
            $headers = [];
            if (isset($response->headers['retry-after'])) {
                $headers['Retry-After'] = $response->headers['retry-after'];
            }
            return new AppException('spotify_rate_limited', translate('spotify_rate_limited'), 429, $headers);
        }

        return new AppException('spotify_request_failed', translate('spotify_request_failed'), 502);
    }
}
