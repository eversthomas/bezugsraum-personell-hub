<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Verschlüsselung mit libsodium (secretbox). Schlüssel liegt in config/keys/.
 */
final class Crypto
{
    private string $key;

    public function __construct(string $key)
    {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException('Schlüssel hat falsche Länge.');
        }
        $this->key = $key;
    }

    public static function fromFile(string $path): self
    {
        $raw = is_file($path) ? file_get_contents($path) : false;
        if ($raw === false) {
            throw new \RuntimeException('Schlüsseldatei fehlt. Mit "php bin/create-key.php" anlegen.');
        }

        return new self(sodium_hex2bin(trim($raw)));
    }

    public static function generateKeyHex(): string
    {
        return sodium_bin2hex(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return $nonce . sodium_crypto_secretbox($plain, $nonce, $this->key);
    }

    public function decrypt(string $cipher): string
    {
        if (strlen($cipher) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new \RuntimeException('Entschlüsselung fehlgeschlagen.');
        }
        $nonce = substr($cipher, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($cipher, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key);
        if ($plain === false) {
            throw new \RuntimeException('Entschlüsselung fehlgeschlagen.');
        }

        return $plain;
    }

    /** Stabiler, schlüsselabhängiger Hash (z. B. für IP-Adressen); nicht umkehrbar. */
    public function hmac(string $purpose, string $value): string
    {
        $subKey = hash_hmac('sha256', 'ph-hmac:' . $purpose, $this->key, true);

        return hash_hmac('sha256', $value, $subKey);
    }
}
