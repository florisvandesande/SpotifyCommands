<?php

declare(strict_types=1);

/** @var array<string, mixed>|null */
$application_config = null;

/**
 * @return array<string, mixed>
 */
function app_config(): array
{
    global $application_config;

    if (!is_array($application_config)) {
        throw new LogicException('Application configuration has not been loaded.');
    }

    return $application_config;
}

function translate(string $key, mixed ...$arguments): string
{
    $config = app_config();
    $locale = in_array($config['app']['locale'], ['nl', 'en'], true) ? $config['app']['locale'] : 'en';
    /** @var array<string, string> $translations */
    $translations = require dirname(__DIR__) . '/locales/' . $locale . '.php';
    $message = $translations[$key] ?? $key;

    return $arguments === [] ? $message : sprintf($message, ...$arguments);
}

function run_application(): void
{
    global $application_config;

    $app_root = dirname(__DIR__);
    $route = '/';

    try {
        $application_config = load_app_config($app_root);
        $route = request_route($application_config['app']['base_url']);
        apply_security_headers();

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            throw new AppException('method_not_allowed', translate('method_not_allowed'), 405, ['Allow' => 'GET']);
        }

        $spotify = create_spotify_client($application_config, $app_root);

        if ($route === '/authorize') {
            handle_authorize($spotify, $application_config);
            return;
        }

        if ($route === '/callback') {
            handle_callback($spotify, $application_config);
            return;
        }

        require_valid_command_secret($application_config);

        if ($route === '/status') {
            handle_status($spotify, $application_config);
            return;
        }

        $playlist_id = ltrim($route, '/');
        if (str_contains($playlist_id, '/') || $playlist_id === '') {
            throw new AppException('route_not_found', translate('route_not_found'), 404);
        }

        handle_playlist_command($spotify, $application_config, $playlist_id, $app_root);
    } catch (AppException $error) {
        log_application_error($error, $application_config, $app_root);
        if ($route === '/callback') {
            send_html_response(
                translate_or_fallback('authorization_failed_title'),
                $error->public_message,
                $error->http_status,
                $application_config,
            );
            return;
        }
        send_error_response($error);
    } catch (Throwable $error) {
        log_application_error($error, $application_config, $app_root);
        $public_error = new AppException(
            'internal_error',
            translate_or_fallback('internal_error'),
            500,
            previous: $error,
        );
        send_error_response($public_error);
    }
}

/** @param array<string, mixed> $config */
function create_spotify_client(array $config, string $app_root): SpotifyClient
{
    $cipher = new TokenCipher(decode_encryption_key($config['app']['token_encryption_key']));
    $storage = new SqliteTokenStorage(
        $app_root . '/data/database/spotify_commands.sqlite',
        $cipher,
    );

    return new SpotifyClient(
        $config['spotify']['client_id'],
        $config['spotify']['client_secret'],
        rtrim($config['app']['base_url'], '/') . '/callback',
        $storage,
        new CurlHttpClient(),
    );
}

function request_route(string $base_url): string
{
    $request_path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
    $base_path = rtrim((string) parse_url($base_url, PHP_URL_PATH), '/');

    if ($base_path !== '' && ($request_path === $base_path || str_starts_with($request_path, $base_path . '/'))) {
        $request_path = substr($request_path, strlen($base_path));
    }

    $route = '/' . ltrim($request_path, '/');
    return $route === '/' ? '/' : rtrim($route, '/');
}

function apply_security_headers(): void
{
    header('Cache-Control: no-store, private');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
}

/** @param array<string, mixed> $config */
function require_valid_command_secret(array $config): void
{
    $provided_secret = $_GET['key'] ?? null;
    if (!is_string($provided_secret) || !hash_equals($config['app']['command_secret'], $provided_secret)) {
        throw new AppException('invalid_command_secret', translate('invalid_command_secret'), 401);
    }
}

/** @param array<string, mixed> $config */
function handle_authorize(SpotifyClient $spotify, array $config): never
{
    require_valid_command_secret($config);
    start_secure_session($config['app']['base_url']);

    $state = bin2hex(random_bytes(32));
    $_SESSION['spotify_oauth_state'] = $state;
    $_SESSION['spotify_oauth_started_at'] = time();

    header('Location: ' . $spotify->authorization_url($state), true, 302);
    exit;
}

/** @param array<string, mixed> $config */
function handle_callback(SpotifyClient $spotify, array $config): void
{
    start_secure_session($config['app']['base_url']);
    $expected_state = $_SESSION['spotify_oauth_state'] ?? null;
    $started_at = $_SESSION['spotify_oauth_started_at'] ?? null;
    unset($_SESSION['spotify_oauth_state'], $_SESSION['spotify_oauth_started_at']);

    $received_state = $_GET['state'] ?? null;
    if (!oauth_state_is_valid($expected_state, $received_state, $started_at, time())) {
        throw new AppException('authorization_state_invalid', translate('authorization_state_invalid'), 400);
    }

    if (isset($_GET['error'])) {
        throw new AppException('authorization_denied', translate('authorization_denied'), 400);
    }

    $code = $_GET['code'] ?? null;
    if (!is_string($code) || $code === '') {
        throw new AppException('authorization_denied', translate('authorization_denied'), 400);
    }

    $spotify->exchange_authorization_code($code);
    session_regenerate_id(true);
    send_html_response(
        translate('authorization_complete_title'),
        translate('authorization_complete_body'),
        200,
        $config,
    );
}

function oauth_state_is_valid(mixed $expected_state, mixed $received_state, mixed $started_at, int $now): bool
{
    return is_string($expected_state)
        && is_string($received_state)
        && hash_equals($expected_state, $received_state)
        && is_int($started_at)
        && $started_at >= $now - 600
        && $started_at <= $now;
}

function start_secure_session(string $base_url): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $cookie_path = rtrim((string) parse_url($base_url, PHP_URL_PATH), '/') . '/';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $cookie_path,
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** @param array<string, mixed> $config */
function handle_status(SpotifyClient $spotify, array $config): void
{
    if (!$spotify->has_tokens()) {
        throw new AppException(
            'spotify_reauthorization_required',
            translate('spotify_reauthorization_required'),
            503,
        );
    }

    $playlists = [];
    foreach ($config['playlists_by_id'] as $playlist) {
        $playlists[] = [
            'name' => $playlist['name'],
            'id' => $playlist['id'],
            'items' => $spotify->check_playlist_access($playlist['id']),
        ];
    }

    send_json_response([
        'ok' => true,
        'code' => 'status_ok',
        'message' => translate('status_ok'),
        'spotify_authorized' => true,
        'playlists' => $playlists,
    ]);
}

/** @param array<string, mixed> $config */
function handle_playlist_command(
    SpotifyClient $spotify,
    array $config,
    string $playlist_id,
    string $app_root,
): void {
    $playlist = $config['playlists_by_id'][$playlist_id] ?? null;
    if (!is_array($playlist)) {
        throw new AppException('playlist_not_configured', translate('playlist_not_configured'), 404);
    }

    with_playlist_lock($app_root, $playlist_id, function () use ($spotify, $playlist): void {
        $track = $spotify->current_track();
        $artists = $track['artists'] === [] ? 'Onbekende artiest' : implode(', ', $track['artists']);

        if ($spotify->playlist_contains($playlist['id'], $track['uri'])) {
            send_json_response([
                'ok' => true,
                'code' => 'already_present',
                'message' => translate('already_present', $track['name'], $artists, $playlist['name']),
                'added' => false,
                'track' => $track,
                'playlist' => ['name' => $playlist['name'], 'id' => $playlist['id']],
            ]);
            return;
        }

        $spotify->add_track($playlist['id'], $track['uri']);
        send_json_response([
            'ok' => true,
            'code' => 'track_added',
            'message' => translate('track_added', $track['name'], $artists, $playlist['name']),
            'added' => true,
            'track' => $track,
            'playlist' => ['name' => $playlist['name'], 'id' => $playlist['id']],
        ]);
    });
}

function with_playlist_lock(string $app_root, string $playlist_id, callable $callback): void
{
    $lock_directory = $app_root . '/data/locks';
    if (!is_dir($lock_directory) && !mkdir($lock_directory, 0770, true) && !is_dir($lock_directory)) {
        throw new AppException('storage_error', translate('storage_error'), 500);
    }

    $handle = fopen($lock_directory . '/' . $playlist_id . '.lock', 'c');
    if ($handle === false) {
        throw new AppException('storage_error', translate('storage_error'), 500);
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new AppException('storage_error', translate('storage_error'), 500);
        }
        $callback();
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/** @param array<string, mixed> $payload */
function send_json_response(array $payload, int $status = 200, array $headers = []): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function send_error_response(AppException $error): void
{
    send_json_response([
        'ok' => false,
        'code' => $error->public_code,
        'message' => $error->public_message,
        'error' => [
            'code' => $error->public_code,
            'message' => $error->public_message,
        ],
    ], $error->http_status, $error->headers);
}

/** @param array<string, mixed>|null $config */
function send_html_response(string $title, string $body, int $status, ?array $config): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; style-src 'self'; base-uri 'none'; frame-ancestors 'none'");
    $base_url = is_array($config) ? rtrim((string) $config['app']['base_url'], '/') : '';
    $stylesheet = htmlspecialchars($base_url . '/assets/style.css', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safe_title = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safe_body = htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    echo '<!doctype html><html lang="nl"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . $safe_title . '</title><link rel="stylesheet" href="' . $stylesheet . '">'
        . '</head><body><main><h1>' . $safe_title . '</h1><p>' . $safe_body . '</p></main></body></html>';
}

function translate_or_fallback(string $key): string
{
    try {
        return translate($key);
    } catch (Throwable) {
        return match ($key) {
            'authorization_failed_title' => 'Spotify authorization failed',
            default => 'An internal error occurred.',
        };
    }
}

/** @param array<string, mixed>|null $config */
function log_application_error(Throwable $error, ?array $config, string $app_root): void
{
    if (!is_array($config) || ($config['logging']['enabled'] ?? false) !== true) {
        return;
    }

    $log_directory = $app_root . '/log';
    if (!is_dir($log_directory) && !mkdir($log_directory, 0770, true) && !is_dir($log_directory)) {
        return;
    }

    $code = $error instanceof AppException ? $error->public_code : 'unhandled_exception';
    $line = sprintf(
        "[%s] %s at %s:%d\n",
        gmdate(DATE_ATOM),
        $code,
        $error->getFile(),
        $error->getLine(),
    );
    error_log($line, 3, $log_directory . '/spotify_commands.log');
}
