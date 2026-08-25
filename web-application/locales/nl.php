<?php

declare(strict_types=1);

return [
    'authorization_complete_title' => 'Spotify is gekoppeld',
    'authorization_complete_body' => 'De Spotify-tokens zijn versleuteld opgeslagen. De command-URL’s zijn nu klaar voor gebruik.',
    'authorization_failed_title' => 'Spotify koppelen is mislukt',
    'authorization_denied' => 'Spotify heeft geen toestemming gegeven. Start de autorisatie opnieuw.',
    'authorization_state_invalid' => 'De autorisatiesessie is ongeldig of verlopen. Start de autorisatie opnieuw.',
    'already_present' => '‘%s’ van %s staat al in %s.',
    'track_added' => '‘%s’ van %s toegevoegd aan %s.',
    'nothing_playing' => 'Spotify speelt momenteel geen nummer af.',
    'unsupported_item' => 'Het huidige Spotify-item is geen ondersteund muzieknummer.',
    'local_track' => 'Lokale Spotify-bestanden kunnen niet aan een playlist worden toegevoegd.',
    'playlist_not_configured' => 'Deze playlist-ID staat niet in config.php.',
    'invalid_command_secret' => 'De command-sleutel ontbreekt of is ongeldig.',
    'method_not_allowed' => 'Alleen een GET-aanroep is toegestaan.',
    'route_not_found' => 'Deze command-route bestaat niet.',
    'spotify_reauthorization_required' => 'De Spotify-koppeling is verlopen. Open de beveiligde /authorize-route opnieuw.',
    'spotify_rate_limited' => 'Spotify ontvangt tijdelijk te veel verzoeken. Probeer het later opnieuw.',
    'spotify_request_failed' => 'Spotify kon het verzoek niet verwerken. Probeer het later opnieuw.',
    'configuration_error' => 'De applicatieconfiguratie ontbreekt of bevat een fout.',
    'storage_error' => 'De beveiligde tokenopslag is niet beschikbaar.',
    'internal_error' => 'Er is een interne fout opgetreden.',
    'status_ok' => 'De configuratie, tokenopslag en Spotify-toegang werken.',
];
