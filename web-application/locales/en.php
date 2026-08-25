<?php

declare(strict_types=1);

return [
    'authorization_complete_title' => 'Spotify is connected',
    'authorization_complete_body' => 'The Spotify tokens were stored securely. The command URLs are ready to use.',
    'authorization_failed_title' => 'Spotify connection failed',
    'authorization_denied' => 'Spotify did not grant permission. Start authorization again.',
    'authorization_state_invalid' => 'The authorization session is invalid or expired. Start authorization again.',
    'already_present' => '“%s” by %s is already in %s.',
    'track_added' => '“%s” by %s was added to %s.',
    'nothing_playing' => 'Spotify is not currently playing a track.',
    'unsupported_item' => 'The current Spotify item is not a supported music track.',
    'local_track' => 'Local Spotify files cannot be added to a playlist.',
    'playlist_not_configured' => 'This playlist ID is not configured in config.php.',
    'invalid_command_secret' => 'The command secret is missing or invalid.',
    'method_not_allowed' => 'Only GET requests are allowed.',
    'route_not_found' => 'This command route does not exist.',
    'spotify_reauthorization_required' => 'The Spotify connection expired. Open the protected /authorize route again.',
    'spotify_rate_limited' => 'Spotify is temporarily receiving too many requests. Try again later.',
    'spotify_request_failed' => 'Spotify could not process the request. Try again later.',
    'configuration_error' => 'The application configuration is missing or invalid.',
    'storage_error' => 'The secure token storage is unavailable.',
    'internal_error' => 'An internal error occurred.',
    'status_ok' => 'Configuration, token storage, and Spotify access are working.',
];
