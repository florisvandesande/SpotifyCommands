<?php

declare(strict_types=1);

/**
 * @return array<string, mixed>
 */
function load_app_config(string $app_root): array
{
    $config_path = $app_root . '/config.php';
    if (!is_file($config_path)) {
        throw new AppException('configuration_error', 'Create config.php from config.example.php before using the application.');
    }

    $config = require $config_path;
    if (!is_array($config)) {
        throw new AppException('configuration_error', 'config.php must return an array.');
    }

    validate_required_config($config);
    $config['playlists_by_id'] = normalize_playlists($config['playlists']);

    return $config;
}

/**
 * @param array<string, mixed> $config
 */
function validate_required_config(array $config): void
{
    $required_values = [
        'app.base_url' => $config['app']['base_url'] ?? null,
        'app.locale' => $config['app']['locale'] ?? null,
        'app.command_secret' => $config['app']['command_secret'] ?? null,
        'app.token_encryption_key' => $config['app']['token_encryption_key'] ?? null,
        'spotify.client_id' => $config['spotify']['client_id'] ?? null,
        'spotify.client_secret' => $config['spotify']['client_secret'] ?? null,
    ];

    foreach ($required_values as $name => $value) {
        if (!is_string($value) || trim($value) === '' || str_starts_with($value, 'replace-with-')) {
            throw new AppException('configuration_error', sprintf('Set a valid value for %s in config.php.', $name));
        }
    }

    $base_url = $config['app']['base_url'];
    if (filter_var($base_url, FILTER_VALIDATE_URL) === false || parse_url($base_url, PHP_URL_SCHEME) !== 'https') {
        throw new AppException('configuration_error', 'app.base_url must be a complete HTTPS URL.');
    }

    if (strlen($config['app']['command_secret']) < 32) {
        throw new AppException('configuration_error', 'app.command_secret must contain at least 32 characters.');
    }

    decode_encryption_key($config['app']['token_encryption_key']);

    if (!isset($config['playlists']) || !is_array($config['playlists']) || $config['playlists'] === []) {
        throw new AppException('configuration_error', 'Configure at least one playlist in config.php.');
    }
}

/**
 * @param array<int, mixed> $playlists
 * @return array<string, array{name: string, id: string, url: string}>
 */
function normalize_playlists(array $playlists): array
{
    $normalized = [];

    foreach ($playlists as $index => $playlist) {
        if (!is_array($playlist)) {
            throw new AppException('configuration_error', sprintf('Playlist %d must be an array.', $index + 1));
        }

        $name = $playlist['name'] ?? null;
        $url = $playlist['url'] ?? null;
        if (!is_string($name) || trim($name) === '' || !is_string($url)) {
            throw new AppException('configuration_error', sprintf('Playlist %d needs a name and URL.', $index + 1));
        }

        $id = extract_spotify_playlist_id($url);
        if (isset($normalized[$id])) {
            throw new AppException('configuration_error', sprintf('Playlist ID %s is configured more than once.', $id));
        }

        $normalized[$id] = [
            'name' => trim($name),
            'id' => $id,
            'url' => $url,
        ];
    }

    return $normalized;
}

function extract_spotify_playlist_id(string $url): string
{
    $parts = parse_url($url);
    $host = strtolower((string) ($parts['host'] ?? ''));
    $path = (string) ($parts['path'] ?? '');

    if (($parts['scheme'] ?? '') !== 'https' || $host !== 'open.spotify.com') {
        throw new AppException('configuration_error', 'Playlist URLs must start with https://open.spotify.com/playlist/.');
    }

    if (preg_match('~^/playlist/([A-Za-z0-9]{10,64})/?$~', $path, $matches) !== 1) {
        throw new AppException('configuration_error', sprintf('Invalid Spotify playlist URL: %s', $url));
    }

    return $matches[1];
}

function decode_encryption_key(string $configured_key): string
{
    if (!str_starts_with($configured_key, 'base64:')) {
        throw new AppException('configuration_error', 'app.token_encryption_key must start with base64:.');
    }

    $decoded = base64_decode(substr($configured_key, 7), true);
    if ($decoded === false || strlen($decoded) !== 32) {
        throw new AppException('configuration_error', 'app.token_encryption_key must contain exactly 32 random bytes.');
    }

    return $decoded;
}
