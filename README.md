# Spotify Playlist Commands

[![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

Add the track currently playing on Spotify to a playlist by calling a private URL.

Spotify Playlist Commands is a small, self-hosted PHP application for personal automations. Connect it to Apple Shortcuts, Keyboard Maestro, Stream Deck, or any tool that can send an HTTPS `GET` request. Each configured playlist gets its own command URL, and duplicate tracks are detected before anything is added.

```text
https://example.com/spotify-commands/PLAYLIST_ID?key=YOUR_COMMAND_SECRET
```

## Features

- Adds the currently playing Spotify track to a configured playlist.
- Prevents the same track from being added twice.
- Returns predictable JSON for use in automation tools.
- Supports public and private Spotify playlists.
- Refreshes short-lived Spotify access tokens automatically.
- Encrypts OAuth tokens before storing them in SQLite.
- Uses one dependency-free PHP application with no build step or background service.
- Includes English and Dutch responses, with English as the default.
- Supports light and dark mode on browser-based authorization pages.

## How it works

1. An automation calls the private URL for one configured playlist.
2. The application validates the command secret and playlist ID.
3. It asks Spotify for the track that is currently playing.
4. It checks the complete playlist for that track.
5. It adds the track when it is not already present.
6. It returns a JSON response containing a stable code and a readable message.

Paused playback, podcasts, advertisements, and local files are rejected without changing the playlist.

## Requirements

- PHP 8.3 or newer.
- Apache with `.htaccess` support and `mod_rewrite` enabled.
- PHP extensions: cURL, OpenSSL, PDO, and PDO SQLite.
- An HTTPS domain or subdomain.
- A Spotify account with permission to edit every configured playlist.
- A Spotify app created in the [Spotify Developer Dashboard](https://developer.spotify.com/dashboard).

New Spotify apps start in Development Mode. Spotify currently requires the app owner to have Premium and limits new Development Mode apps to five authenticated users. See Spotify's [quota mode documentation](https://developer.spotify.com/documentation/web-api/concepts/quota-modes) for the current rules.

Composer, Node.js, Python, an external database, and a background process are not required.

## Installation

### 1. Download the project

Clone the repository or download its source archive from GitHub:

```bash
git clone https://github.com/florisvandesande/SpotifyCommands.git
cd SpotifyCommands
```

The deployable application is inside `web-application/`.

### 2. Create a Spotify app

1. Sign in to the [Spotify Developer Dashboard](https://developer.spotify.com/dashboard).
2. Create an app and open its settings.
3. Add the public callback URL for your installation as a Redirect URI:

   ```text
   https://example.com/spotify-commands/callback
   ```

4. Save the settings.
5. Copy the Client ID and Client Secret for the next step.

The Redirect URI must exactly match your configured base URL followed by `/callback`. Its scheme, domain, path, letter case, and trailing slash must be identical. Spotify requires HTTPS except for explicit loopback addresses; see the [Redirect URI requirements](https://developer.spotify.com/documentation/web-api/concepts/redirect_uri).

The application requests these Spotify scopes:

- `user-read-currently-playing`
- `playlist-read-private`
- `playlist-modify-public`
- `playlist-modify-private`

### 3. Create the configuration

From the repository root, copy the safe template:

```bash
cp web-application/config.example.php web-application/config.php
```

Generate two unrelated random values:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
php -r "echo 'base64:', base64_encode(random_bytes(32)), PHP_EOL;"
```

Open `web-application/config.php` and set:

- `app.base_url`: the public URL without a trailing slash;
- `app.locale`: `en` or `nl`;
- `app.command_secret`: the first generated value;
- `app.token_encryption_key`: the second generated value, including `base64:`;
- `spotify.client_id` and `spotify.client_secret`;
- one or more editable Spotify playlists.

Example:

```php
<?php

declare(strict_types=1);

return [
    'app' => [
        'base_url' => 'https://example.com/spotify-commands',
        'locale' => 'en',
        'command_secret' => 'paste-the-generated-64-character-value-here',
        'token_encryption_key' => 'base64:paste-the-generated-key-here',
    ],
    'spotify' => [
        'client_id' => 'your-spotify-client-id',
        'client_secret' => 'your-spotify-client-secret',
    ],
    'playlists' => [
        [
            'name' => 'Favorites',
            'url' => 'https://open.spotify.com/playlist/37i9dQZF1DXcBWIGoYBM5M',
        ],
    ],
    'logging' => [
        'enabled' => true,
    ],
];
```

A Spotify sharing URL may include a query such as `?si=...`; the application extracts the playlist ID from it.

### 4. Protect the configuration

`config.php` contains secrets and must never be committed, shared, or placed in a support request. It is excluded by the repository's `.gitignore`. Confirm this before publishing your changes:

```bash
git status --short
git check-ignore -v web-application/config.php
```

The second command must report that `web-application/config.php` is ignored. SQLite databases, logs, locks, and other runtime files are ignored as well.

If a real secret is ever committed, removing the file in a later commit is not sufficient. Rotate the Spotify Client Secret and both generated application secrets immediately.

### 5. Deploy the application

Upload the **contents** of `web-application/` to the server directory that serves your chosen base URL. For example:

```text
Server directory: /public_html/spotify-commands/
Public URL:       https://example.com/spotify-commands/
```

Keep all included `.htaccess` files. They provide clean URLs, redirect HTTP to HTTPS, and block direct web access to configuration, tokens, logs, tests, and internal source files.

The PHP process needs write access to:

```text
data/database/
data/locks/
log/
```

On many shared hosts, directory permissions of `750` work. Use `770` only when the PHP process cannot create its database, lock, or log files. Do not use `777`.

### 6. Authorize Spotify

Open the protected authorization URL in a browser:

```text
https://example.com/spotify-commands/authorize?key=YOUR_COMMAND_SECRET
```

Sign in with the Spotify account that owns or can edit the configured playlists, then approve access. Spotify redirects back to `/callback`, where the application encrypts and stores the OAuth tokens in its local SQLite database.

Spotify refresh tokens for Developer Dashboard apps currently expire after six months. When a command returns `spotify_reauthorization_required`, open the authorization URL again. You do not need to delete the database. See Spotify's [refresh-token documentation](https://developer.spotify.com/documentation/web-api/tutorials/refreshing-tokens).

### 7. Verify the installation

Open the protected status route:

```text
https://example.com/spotify-commands/status?key=YOUR_COMMAND_SECRET
```

A working installation returns a response similar to:

```json
{
  "ok": true,
  "code": "status_ok",
  "message": "Configuration, token storage, and Spotify access are working.",
  "spotify_authorized": true,
  "playlists": [
    {
      "name": "Favorites",
      "id": "37i9dQZF1DXcBWIGoYBM5M",
      "items": 42
    }
  ]
}
```

If a playlist check fails, confirm that the authorized Spotify account can edit it and that its URL is correct in `config.php`.

## Command URLs

Copy the playlist ID from its Spotify URL and append it to the application base URL:

```text
Spotify playlist:
https://open.spotify.com/playlist/37i9dQZF1DXcBWIGoYBM5M

Command URL:
https://example.com/spotify-commands/37i9dQZF1DXcBWIGoYBM5M?key=YOUR_COMMAND_SECRET
```

A successful request returns:

```json
{
  "ok": true,
  "code": "track_added",
  "message": "“Track name” by Artist was added to Favorites.",
  "added": true,
  "track": {
    "name": "Track name",
    "artists": ["Artist"],
    "uri": "spotify:track:example"
  },
  "playlist": {
    "name": "Favorites",
    "id": "37i9dQZF1DXcBWIGoYBM5M"
  }
}
```

If the playlist already contains the track, the request still succeeds with HTTP `200`, `code: "already_present"`, and `added: false`.

## Automation examples

### Apple Shortcuts

Create one Shortcut per playlist:

1. Add **Get Contents of URL**.
2. Enter the complete command URL and keep the request method set to `GET`.
3. Add **Get Dictionary Value** and read the `message` key from the response.
4. Add **Show Notification** and use that dictionary value as its text.
5. Give the Shortcut a recognizable playlist name and optionally add it to the Home Screen, Menu Bar, or Control Center.

### Keyboard Maestro

Create one macro per playlist:

1. Add a trigger, such as a hot key or Stream Deck key.
2. Add **Get a URL** and enter the complete command URL.
3. Save the result to `Local Spotify Response`.
4. Add **Set Variable to Text**, name it `Local Spotify Message`, and use:

   ```text
   %JSONValue%Local Spotify Response.message%
   ```

5. Add **Display Text Briefly** or **Notification** with:

   ```text
   %Variable%Local Spotify Message%
   ```

Using **Get a URL** keeps the request in the background. **Open a URL** opens a browser and may leave the secret-bearing URL in browser history.

For better local secret handling, store the command secret as a generic password in macOS Keychain and retrieve it with Keyboard Maestro's **Set Variable to Keychain Password** action. You can then construct the URL with a Keyboard Maestro password variable instead of saving the secret directly in the macro.

### Stream Deck

Use an HTTP-request action or plugin when available:

1. Configure a `GET` request with the complete command URL.
2. Display the JSON `message` value as feedback.
3. Create one key per playlist.

The standard **Website** action also works, but opens the JSON response in a browser.

## Routes

All public routes use clean URLs without a `.php` extension.

| Method | Route | Purpose |
| --- | --- | --- |
| `GET` | `/authorize?key=…` | Start Spotify authorization. |
| `GET` | `/callback` | Receive Spotify's OAuth callback. |
| `GET` | `/status?key=…` | Validate configuration, stored tokens, and playlist access. |
| `GET` | `/{playlist_id}?key=…` | Add the current track to a configured playlist. |

Except for Spotify's callback, every route requires the command secret.

## Response codes

| Code | Meaning | Suggested action |
| --- | --- | --- |
| `track_added` | The current track was added. | None. |
| `already_present` | The playlist already contains the track. | None. |
| `nothing_playing` | Spotify is paused or has no active item. | Start a Spotify track and retry. |
| `unsupported_item` | The active item is not a music track. | Play a regular Spotify track. |
| `local_track` | Spotify is playing a local file. | Play a Spotify-hosted track. |
| `playlist_not_configured` | The URL contains an unknown playlist ID. | Add that playlist to `config.php`. |
| `invalid_command_secret` | The command secret is absent or incorrect. | Check the `key` query parameter. |
| `spotify_reauthorization_required` | Authorization is missing, revoked, or expired. | Open the protected `/authorize` route again. |
| `spotify_rate_limited` | Spotify has temporarily limited requests. | Wait and retry. |
| `spotify_request_failed` | Spotify rejected or could not complete the request. | Retry, then inspect the application log. |
| `configuration_error` | `config.php` is absent or invalid. | Compare it with `config.example.php`. |
| `storage_error` | Token or lock storage is unavailable. | Check directory existence and permissions. |

Error responses consistently contain both top-level `code` and `message` fields and a nested `error` object:

```json
{
  "ok": false,
  "code": "nothing_playing",
  "message": "Spotify is not currently playing a track.",
  "error": {
    "code": "nothing_playing",
    "message": "Spotify is not currently playing a track."
  }
}
```

## Security notes

- Treat every command URL as a password because it contains `app.command_secret`.
- Use HTTPS only. The included Apache configuration redirects plain HTTP requests.
- Do not publish command URLs in screenshots, logs, Shortcut exports, Stream Deck profiles, or macro exports.
- Be aware that query strings may appear in browser history, proxy logs, and web-server access logs.
- Use a dedicated random command secret rather than a reused password.
- Keep `config.php`, the SQLite database, and runtime logs outside Git.
- Rotate exposed credentials immediately.
- Set `logging.enabled` to `false` in production if application-level error logging is not needed.

OAuth access and refresh tokens are encrypted at rest with AES-256-GCM before being stored in `data/database/spotify_commands.sqlite`. The encryption key remains in the ignored `config.php`. This protects the database file by itself; it does not protect a server on which both the database and configuration are compromised.

## Project structure

```text
web-application/
├── .htaccess              Apache routing and access protection
├── assets/                Browser styles
├── config.example.php     Safe configuration template
├── data/
│   ├── database/          Encrypted SQLite token storage
│   └── locks/             Per-playlist concurrency locks
├── includes/              Application and Spotify integration code
├── locales/               English and Dutch messages
├── log/                   Optional application error log
├── tests/                 Dependency-free test suite
└── index.php              Public entry point
```

## Development

Run the dependency-free test suite from the repository root:

```bash
php web-application/tests/run.php
```

Check every committed PHP file for syntax errors without loading the secret configuration:

```bash
find web-application -name '*.php' ! -name 'config.php' -print0 | xargs -0 -n1 php -l
php -l web-application/includes/config.php
```

The project deliberately avoids a framework and package manager. Keep changes compatible with PHP 8.3, Apache shared hosting, and the existing dependency-free test setup.

## Limitations

- The application is intended for a small number of trusted users, not as a public multi-user service.
- Only configured playlists can be changed.
- Only Spotify-hosted music tracks are supported.
- Duplicate detection scans the playlist and may require several Spotify API requests for large playlists.
- Spotify API availability, quotas, scopes, and account requirements remain subject to Spotify's platform rules.

## Contributing

Issues and focused pull requests are welcome. Before submitting a change:

1. Do not include `config.php`, databases, logs, tokens, command URLs, or credentials.
2. Keep production code in PHP, HTML, CSS, and vanilla JavaScript.
3. Add or update tests for behavior changes.
4. Run the checks documented above.
5. Explain the user-visible effect and any setup changes in the pull request.

## License

This project is available under the [MIT License](LICENSE).

## Disclaimer

This is an independent open-source project. It is not affiliated with, endorsed by, or sponsored by Spotify. Spotify and the Spotify logo are trademarks of Spotify AB. Use of the Spotify Web API is subject to Spotify's developer terms and policies.
