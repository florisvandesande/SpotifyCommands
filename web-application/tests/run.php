<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

final class MemoryTokenStorage implements TokenStorage
{
    /** @param array{access_token: string, refresh_token: string, expires_at: int}|null $tokens */
    public function __construct(public ?array $tokens)
    {
    }

    public function load(): ?array
    {
        return $this->tokens;
    }

    public function save(string $access_token, string $refresh_token, int $expires_at): void
    {
        $this->tokens = compact('access_token', 'refresh_token', 'expires_at');
    }

    public function with_exclusive_lock(callable $callback): mixed
    {
        return $callback();
    }
}

final class QueueHttpClient implements HttpClient
{
    /** @var array<int, HttpResponse> */
    private array $responses;

    /** @var array<int, array{method: string, url: string, headers: array<string, string>, body: string|null}> */
    public array $requests = [];

    /** @param array<int, HttpResponse> $responses */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        $response = array_shift($this->responses);
        if (!$response instanceof HttpResponse) {
            throw new RuntimeException('The fake HTTP response queue is empty.');
        }

        return $response;
    }
}

/** @var array<string, mixed> $application_config */
$application_config = [
    'app' => [
        'locale' => 'nl',
        'base_url' => 'https://example.com/muziek/commands',
    ],
];

$tests = [];

function test(string $name, callable $callback): void
{
    global $tests;
    $tests[$name] = $callback;
}

function assert_true(bool $condition, string $message = 'Expected condition to be true.'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assert_same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            "Expected %s, received %s.",
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
}

function assert_app_exception(string $expected_code, callable $callback): void
{
    try {
        $callback();
    } catch (AppException $error) {
        assert_same($expected_code, $error->public_code);
        return;
    }

    throw new RuntimeException('Expected AppException was not thrown.');
}

function valid_token_storage(?array $tokens = null): MemoryTokenStorage
{
    return new MemoryTokenStorage($tokens ?? [
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'expires_at' => time() + 3600,
    ]);
}

function spotify_client(
    QueueHttpClient $http,
    ?MemoryTokenStorage $storage = null,
    ?callable $sleeper = null,
): SpotifyClient {
    return new SpotifyClient(
        'client-id',
        'client-secret',
        'https://example.com/muziek/commands/callback',
        $storage ?? valid_token_storage(),
        $http,
        $sleeper,
    );
}

function json_response(int $status, array $payload, array $headers = []): HttpResponse
{
    return new HttpResponse($status, json_encode($payload, JSON_THROW_ON_ERROR), $headers);
}

test('playlist URL parsing accepts canonical URLs and strips query parameters', function (): void {
    assert_same(
        '37i9dQZF1DXcBWIGoYBM5M',
        extract_spotify_playlist_id('https://open.spotify.com/playlist/37i9dQZF1DXcBWIGoYBM5M?si=example'),
    );
    assert_app_exception('configuration_error', static fn () => extract_spotify_playlist_id(
        'https://example.com/playlist/37i9dQZF1DXcBWIGoYBM5M'
    ));
});

test('configuration rejects duplicate playlist IDs and short command secrets', function (): void {
    assert_app_exception('configuration_error', static fn () => normalize_playlists([
        ['name' => 'One', 'url' => 'https://open.spotify.com/playlist/37i9dQZF1DXcBWIGoYBM5M'],
        ['name' => 'Two', 'url' => 'https://open.spotify.com/playlist/37i9dQZF1DXcBWIGoYBM5M'],
    ]));

    assert_app_exception('configuration_error', static fn () => validate_required_config([
        'app' => [
            'base_url' => 'https://example.com',
            'locale' => 'nl',
            'command_secret' => 'short',
            'token_encryption_key' => 'base64:' . base64_encode(random_bytes(32)),
        ],
        'spotify' => ['client_id' => 'id', 'client_secret' => 'secret'],
        'playlists' => [['name' => 'One', 'url' => 'https://open.spotify.com/playlist/37i9dQZF1DXcBWIGoYBM5M']],
    ]));
});

test('command secret comparison rejects a missing or invalid secret', function () use (&$application_config): void {
    $application_config['app']['command_secret'] = str_repeat('a', 64);
    $_GET = [];
    assert_app_exception('invalid_command_secret', static fn () => require_valid_command_secret(app_config()));
    $_GET = ['key' => str_repeat('b', 64)];
    assert_app_exception('invalid_command_secret', static fn () => require_valid_command_secret(app_config()));
});

test('OAuth state must match and be no more than ten minutes old', function (): void {
    $now = 1_800_000_000;
    assert_true(oauth_state_is_valid('state', 'state', $now - 599, $now));
    assert_true(!oauth_state_is_valid('state', 'wrong', $now - 10, $now));
    assert_true(!oauth_state_is_valid('state', 'state', $now - 601, $now));
    assert_true(!oauth_state_is_valid('state', 'state', $now + 1, $now));
});

test('tokens round-trip through encryption and SQLite without plaintext storage', function (): void {
    $directory = sys_get_temp_dir() . '/spotify-command-tests-' . bin2hex(random_bytes(6));
    assert_true(mkdir($directory, 0700));
    $database_path = $directory . '/tokens.sqlite';
    $storage = new SqliteTokenStorage($database_path, new TokenCipher(random_bytes(32)));
    $storage->save('plain-access', 'plain-refresh', 1234567890);
    assert_same([
        'access_token' => 'plain-access',
        'refresh_token' => 'plain-refresh',
        'expires_at' => 1234567890,
    ], $storage->load());
    $database_bytes = file_get_contents($database_path);
    assert_true(is_string($database_bytes) && !str_contains($database_bytes, 'plain-refresh'));
    unlink($database_path);
    @unlink($database_path . '-wal');
    @unlink($database_path . '-shm');
    rmdir($directory);
});

test('current playback accepts an active remote track', function (): void {
    $http = new QueueHttpClient([json_response(200, [
        'is_playing' => true,
        'item' => [
            'type' => 'track',
            'is_local' => false,
            'name' => 'Test Track',
            'uri' => 'spotify:track:abc123',
            'artists' => [['name' => 'Test Artist']],
        ],
    ])]);
    assert_same([
        'name' => 'Test Track',
        'artists' => ['Test Artist'],
        'uri' => 'spotify:track:abc123',
    ], spotify_client($http)->current_track());
});

test('empty, paused, episode, and local playback are rejected', function (): void {
    assert_app_exception('nothing_playing', static fn () => spotify_client(
        new QueueHttpClient([new HttpResponse(204, '')])
    )->current_track());

    assert_app_exception('nothing_playing', static fn () => spotify_client(
        new QueueHttpClient([json_response(200, ['is_playing' => false])])
    )->current_track());

    assert_app_exception('unsupported_item', static fn () => spotify_client(
        new QueueHttpClient([json_response(200, [
            'is_playing' => true,
            'item' => ['type' => 'episode'],
        ])])
    )->current_track());

    assert_app_exception('local_track', static fn () => spotify_client(
        new QueueHttpClient([json_response(200, [
            'is_playing' => true,
            'item' => ['type' => 'track', 'is_local' => true],
        ])])
    )->current_track());
});

test('duplicate lookup follows every playlist page', function (): void {
    $http = new QueueHttpClient([
        json_response(200, ['items' => [['item' => ['uri' => 'spotify:track:first']]], 'total' => 2]),
        json_response(200, ['items' => [['item' => ['uri' => 'spotify:track:target']]], 'total' => 2]),
    ]);
    assert_true(spotify_client($http)->playlist_contains('playlist-id', 'spotify:track:target'));
    assert_true(str_contains($http->requests[1]['url'], 'offset=1'));
});

test('adding a track sends a JSON body to the current items endpoint', function (): void {
    $http = new QueueHttpClient([json_response(201, ['snapshot_id' => 'snapshot'])]);
    spotify_client($http)->add_track('playlist-id', 'spotify:track:target');
    assert_same('POST', $http->requests[0]['method']);
    assert_true(str_ends_with($http->requests[0]['url'], '/playlists/playlist-id/items'));
    assert_same('{"uris":["spotify:track:target"]}', $http->requests[0]['body']);
});

test('a short Spotify rate limit is retried once', function (): void {
    $slept = [];
    $http = new QueueHttpClient([
        json_response(429, ['error' => ['message' => 'slow down']], ['retry-after' => '2']),
        json_response(200, [
            'is_playing' => true,
            'item' => [
                'type' => 'track',
                'is_local' => false,
                'name' => 'Track',
                'uri' => 'spotify:track:target',
                'artists' => [],
            ],
        ]),
    ]);
    spotify_client($http, sleeper: static function (int $seconds) use (&$slept): void {
        $slept[] = $seconds;
    })->current_track();
    assert_same([2], $slept);
    assert_same(2, count($http->requests));
});

test('refresh token rotation is persisted before the API request', function (): void {
    $storage = valid_token_storage([
        'access_token' => 'expired-access',
        'refresh_token' => 'old-refresh',
        'expires_at' => time() - 10,
    ]);
    $http = new QueueHttpClient([
        json_response(200, [
            'access_token' => 'new-access',
            'refresh_token' => 'new-refresh',
            'expires_in' => 3600,
        ]),
        new HttpResponse(204, ''),
    ]);
    assert_app_exception('nothing_playing', static fn () => spotify_client($http, $storage)->current_track());
    assert_same('new-refresh', $storage->tokens['refresh_token']);
    assert_true(str_contains((string) $http->requests[0]['body'], 'refresh_token=old-refresh'));
});

test('invalid refresh tokens require reauthorization', function (): void {
    $storage = valid_token_storage([
        'access_token' => 'expired-access',
        'refresh_token' => 'expired-refresh',
        'expires_at' => time() - 10,
    ]);
    $http = new QueueHttpClient([json_response(400, ['error' => 'invalid_grant'])]);
    assert_app_exception(
        'spotify_reauthorization_required',
        static fn () => spotify_client($http, $storage)->current_track(),
    );
});

test('playlist lock is held while its callback runs', function (): void {
    $directory = sys_get_temp_dir() . '/spotify-lock-tests-' . bin2hex(random_bytes(6));
    assert_true(mkdir($directory . '/data/locks', 0700, true));
    with_playlist_lock($directory, 'playlist-id', static function () use ($directory): void {
        $second = fopen($directory . '/data/locks/playlist-id.lock', 'c');
        assert_true(is_resource($second));
        assert_true(!flock($second, LOCK_EX | LOCK_NB));
        fclose($second);
    });
    unlink($directory . '/data/locks/playlist-id.lock');
    rmdir($directory . '/data/locks');
    rmdir($directory . '/data');
    rmdir($directory);
});

test('error JSON exposes only the stable public fields', function (): void {
    ob_start();
    send_error_response(new AppException('safe_code', 'Safe message', 400));
    $payload = json_decode((string) ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
    assert_same(false, $payload['ok']);
    assert_same('safe_code', $payload['code']);
    assert_same('Safe message', $payload['message']);
    assert_true(!isset($payload['trace']));
});

$failures = 0;
foreach ($tests as $name => $callback) {
    try {
        $callback();
        fwrite(STDOUT, "PASS  {$name}\n");
    } catch (Throwable $error) {
        $failures++;
        fwrite(STDERR, "FAIL  {$name}\n      {$error->getMessage()}\n");
    }
}

fwrite(STDOUT, sprintf("\n%d tests, %d failures\n", count($tests), $failures));
exit($failures === 0 ? 0 : 1);
