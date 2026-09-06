<?php

declare(strict_types=1);

return [
    'app' => [
        'base_url' => 'https://example.com/spotify-commands',
        'locale' => 'en',
        // Generate with: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
        'command_secret' => 'replace-with-a-random-64-character-secret',
        // Generate with: php -r "echo 'base64:', base64_encode(random_bytes(32)), PHP_EOL;"
        'token_encryption_key' => 'base64:replace-with-a-base64-encoded-32-byte-key',
    ],
    'spotify' => [
        'client_id' => 'replace-with-your-spotify-client-id',
        'client_secret' => 'replace-with-your-spotify-client-secret',
    ],
    'playlists' => [
        [
            'name' => 'Favorites',
            'url' => 'https://open.spotify.com/playlist/37i9dQZF1DXcBWIGoYBM5M',
        ],
        [
            'name' => 'Focus',
            'url' => 'https://open.spotify.com/playlist/37i9dQZF1DWZd79rJ6a7lp',
        ],
    ],
    'logging' => [
        'enabled' => true,
    ],
];
