<?php

declare(strict_types=1);

/**
 * @phpstan-type TokenRecord array{access_token: string, refresh_token: string, expires_at: int}
 */
interface TokenStorage
{
    /** @return array{access_token: string, refresh_token: string, expires_at: int}|null */
    public function load(): ?array;

    public function save(string $access_token, string $refresh_token, int $expires_at): void;

    public function with_exclusive_lock(callable $callback): mixed;
}

final class SqliteTokenStorage implements TokenStorage
{
    private PDO $database;

    private string $lock_path;

    public function __construct(string $database_path, private readonly TokenCipher $cipher)
    {
        $directory = dirname($database_path);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new AppException('storage_error', 'The database directory could not be created.');
        }

        $this->lock_path = $database_path . '.refresh.lock';

        try {
            $this->database = new PDO('sqlite:' . $database_path, options: [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $this->database->exec('PRAGMA journal_mode = WAL');
            $this->database->exec('PRAGMA busy_timeout = 5000');
            $this->database->exec(
                'CREATE TABLE IF NOT EXISTS oauth_tokens ('
                . 'id INTEGER PRIMARY KEY CHECK (id = 1), '
                . 'access_token TEXT NOT NULL, '
                . 'refresh_token TEXT NOT NULL, '
                . 'expires_at INTEGER NOT NULL, '
                . 'updated_at TEXT NOT NULL'
                . ')'
            );
        } catch (PDOException $error) {
            throw new AppException('storage_error', 'The SQLite token database could not be opened.', previous: $error);
        }
    }

    public function load(): ?array
    {
        try {
            $row = $this->database->query(
                'SELECT access_token, refresh_token, expires_at FROM oauth_tokens WHERE id = 1'
            )->fetch();
        } catch (PDOException $error) {
            throw new AppException('storage_error', 'The Spotify tokens could not be read.', previous: $error);
        }

        if ($row === false) {
            return null;
        }

        return [
            'access_token' => $this->cipher->decrypt((string) $row['access_token']),
            'refresh_token' => $this->cipher->decrypt((string) $row['refresh_token']),
            'expires_at' => (int) $row['expires_at'],
        ];
    }

    public function save(string $access_token, string $refresh_token, int $expires_at): void
    {
        try {
            $statement = $this->database->prepare(
                'INSERT INTO oauth_tokens (id, access_token, refresh_token, expires_at, updated_at) '
                . 'VALUES (1, :access_token, :refresh_token, :expires_at, :updated_at) '
                . 'ON CONFLICT(id) DO UPDATE SET '
                . 'access_token = excluded.access_token, '
                . 'refresh_token = excluded.refresh_token, '
                . 'expires_at = excluded.expires_at, '
                . 'updated_at = excluded.updated_at'
            );
            $statement->execute([
                'access_token' => $this->cipher->encrypt($access_token),
                'refresh_token' => $this->cipher->encrypt($refresh_token),
                'expires_at' => $expires_at,
                'updated_at' => gmdate(DATE_ATOM),
            ]);
        } catch (PDOException $error) {
            throw new AppException('storage_error', 'The Spotify tokens could not be saved.', previous: $error);
        }
    }

    public function with_exclusive_lock(callable $callback): mixed
    {
        $handle = fopen($this->lock_path, 'c');
        if ($handle === false) {
            throw new AppException('storage_error', 'The token refresh lock could not be opened.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new AppException('storage_error', 'The token refresh lock could not be acquired.');
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
