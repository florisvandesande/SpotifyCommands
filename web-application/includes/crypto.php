<?php

declare(strict_types=1);

final class TokenCipher
{
    private const CIPHER = 'aes-256-gcm';

    public function __construct(private readonly string $key)
    {
        if (strlen($key) !== 32) {
            throw new InvalidArgumentException('The token encryption key must contain 32 bytes.');
        }
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );

        if ($ciphertext === false) {
            throw new AppException('storage_error', 'Token encryption failed.');
        }

        return base64_encode($nonce . $tag . $ciphertext);
    }

    public function decrypt(string $payload): string
    {
        $decoded = base64_decode($payload, true);
        if ($decoded === false || strlen($decoded) < 29) {
            throw new AppException('storage_error', 'The encrypted token payload is invalid.');
        }

        $nonce = substr($decoded, 0, 12);
        $tag = substr($decoded, 12, 16);
        $ciphertext = substr($decoded, 28);
        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );

        if ($plaintext === false) {
            throw new AppException('storage_error', 'The encrypted tokens could not be decrypted.');
        }

        return $plaintext;
    }
}
