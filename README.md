# Spotify playlist commands

This PHP module adds the currently playing Spotify track to a configured playlist when you call a private URL. It is intended for iOS Shortcuts, Stream Deck, or another personal automation tool.

Example command URL:

```text
https://florisvandesande.com/muziek/commands/37i9dQZF1DXcBWIGoYBM5M?key=YOUR_COMMAND_SECRET
```

The playlist ID in the URL must belong to a playlist listed in `config.php`. A track that is already present is not added a second time.

## Requirements

- PHP 8.3 or newer.
- Apache with `.htaccess` and `mod_rewrite` enabled.
- PHP extensions: cURL, OpenSSL, PDO, and PDO SQLite.
- HTTPS on the public domain.
- A Spotify account that may edit every configured playlist.
- A Spotify Developer app. In Development Mode, the app owner currently needs Spotify Premium.

The application does not need Composer, Python, a background process, or an external database.

## 1. Create the Spotify app

1. Sign in to the [Spotify Developer Dashboard](https://developer.spotify.com/dashboard).
2. Create a new app, or open an app that you already own.
3. Open the app settings.
4. Add this exact Redirect URI:

```text
https://florisvandesande.com/muziek/commands/callback
```

5. Save the Spotify app settings.
6. Copy the Client ID and Client Secret. You will put both values in `config.php` later.

The scheme, domain, path, letter case, and trailing slash must match exactly. Do not add a trailing slash to the callback above.

## 2. Upload the module

Upload the contents of `web-application/spotify-playlist-commands/` to the server directory that serves:

```text
https://florisvandesande.com/muziek/commands/
```

Keep the included `.htaccess` files. They provide clean URLs and prevent direct web access to configuration, tokens, logs, tests, and internal PHP files.

The PHP process must be able to write to these directories:

```text
data/database/
data/locks/
log/
```

On most shared hosting accounts, directories with permission `750` or `770` work. Start with `750`. Use `770` only when PHP cannot create the database or log file. Do not use `777`.

## 3. Create config.php

Copy `config.example.php` to `config.php`:

```bash
cp config.example.php config.php
```

Generate two unrelated random values on your Mac:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
php -r "echo 'base64:', base64_encode(random_bytes(32)), PHP_EOL;"
```

Use the first output as `app.command_secret` and the second as `app.token_encryption_key`. Then fill in the Spotify Client ID, Client Secret, playlist names, and Spotify playlist URLs.

Example playlist entry:

```php
[
    'name' => 'Favorieten',
    'url' => 'https://open.spotify.com/playlist/37i9dQZF1DXcBWIGoYBM5M',
],
```

The complete URL may contain a Spotify sharing query such as `?si=...`; the application extracts the playlist ID safely.

Before committing anything, check:

```bash
git status --short
git check-ignore -v web-application/spotify-playlist-commands/config.php
```

`config.php` must be reported as ignored. Never upload it to GitHub, send it to somebody else, or include it in a support message. The SQLite file is also ignored and contains only encrypted OAuth tokens.

## 4. Authorize Spotify

Open this URL in a browser, replacing the final value with `app.command_secret`:

```text
https://florisvandesande.com/muziek/commands/authorize?key=YOUR_COMMAND_SECRET
```

Sign in to the Spotify account that owns or collaborates on the playlists and grant permission. Spotify returns to `/callback`; the page confirms that the encrypted tokens were saved in the local SQLite database.

Spotify refresh tokens currently expire after six months. When a command later reports `spotify_reauthorization_required`, open the protected `/authorize` URL again. You do not need to remove the existing database.

## 5. Check the installation

Call the protected status route:

```text
https://florisvandesande.com/muziek/commands/status?key=YOUR_COMMAND_SECRET
```

Expected response:

```json
{
  "ok": true,
  "code": "status_ok",
  "message": "De configuratie, tokenopslag en Spotify-toegang werken.",
  "spotify_authorized": true,
  "playlists": [
    {
      "name": "Favorieten",
      "id": "37i9dQZF1DXcBWIGoYBM5M",
      "items": 42
    }
  ]
}
```

If one playlist returns an error, verify that the authorized account owns it or has collaborator access and that its URL in `config.php` is correct.

## 6. Create a command URL

Copy the playlist ID from its Spotify URL and append it directly to the commands base URL:

```text
Spotify URL:
https://open.spotify.com/playlist/37i9dQZF1DXcBWIGoYBM5M

Command URL:
https://florisvandesande.com/muziek/commands/37i9dQZF1DXcBWIGoYBM5M?key=YOUR_COMMAND_SECRET
```

Successful response:

```json
{
  "ok": true,
  "code": "track_added",
  "message": "‘Tracknaam’ van Artiest toegevoegd aan Favorieten.",
  "added": true,
  "track": {
    "name": "Tracknaam",
    "artists": ["Artiest"],
    "uri": "spotify:track:example"
  },
  "playlist": {
    "name": "Favorieten",
    "id": "37i9dQZF1DXcBWIGoYBM5M"
  }
}
```

When the track is already in the playlist, the response still has HTTP status 200 but uses `code: "already_present"` and `added: false`.

## iOS Shortcut

Create one Shortcut per playlist:

1. Add the action **Get Contents of URL**.
2. Paste the complete command URL, including `?key=...`.
3. Keep the request method set to `GET`.
4. Add **Get Dictionary Value** and use the key `message` from the result of the previous action.
5. Add **Show Notification** and use the dictionary value as its text.
6. Give the Shortcut a recognizable playlist name and optionally add it to the Home Screen or Control Center.

Run the Shortcut while Spotify is actively playing a normal streaming track. Paused playback, podcasts, advertisements, and local files deliberately return an error instead of changing a playlist.

## Stream Deck on macOS

Create one Website action per playlist:

1. Add a **Website** action to the Stream Deck profile.
2. Paste the complete command URL, including the playlist ID and `?key=...`.
3. Name the button after the playlist.
4. Press the button while Spotify is playing.

The URL returns JSON in the browser. If your Stream Deck setup uses an HTTP-request action or plugin, configure it to perform a `GET` request and display the returned `message` field. Treat the command URL as a password: do not publish screenshots or exported profiles containing it.

## Response codes

| Code | Meaning | Action |
| --- | --- | --- |
| `track_added` | The playing track was added. | None. |
| `already_present` | The track was already in the playlist. | None. |
| `nothing_playing` | Spotify is paused or has no active player. | Start playback and try again. |
| `unsupported_item` | The active item is not a music track. | Play a normal track. |
| `local_track` | Spotify is playing a local file. | Play a Spotify-hosted track. |
| `playlist_not_configured` | The URL contains an unknown playlist ID. | Add the playlist URL to `config.php`. |
| `invalid_command_secret` | The URL has no valid command secret. | Correct the `key` query parameter. |
| `spotify_reauthorization_required` | The Spotify authorization is missing or expired. | Open `/authorize?key=...` again. |
| `spotify_rate_limited` | Spotify temporarily limited requests. | Wait and try again. |

Technical errors are written to `log/spotify_commands.log` only when `logging.enabled` is `true`. Secrets and tokens are never included in application logs.

## Local checks

Run the dependency-free tests:

```bash
php tests/run.php
```

Check every PHP file for syntax errors:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```
