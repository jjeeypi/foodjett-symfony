<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class SensitiveDataCipher
{
    private string $key;

    public function __construct(#[Autowire('%kernel.secret%')] string $secret)
    {
        $this->key = hash('sha256', $secret, true);
    }

    /** @param array<string, mixed> $value */
    public function encrypt(array $value): string
    {
        $plaintext = json_encode($value, JSON_THROW_ON_ERROR);
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if (false === $ciphertext) {
            throw new \RuntimeException('Sensitive data could not be encrypted.');
        }

        return base64_encode("FJ1\0".$iv.$tag.$ciphertext);
    }

    /** @return array<string, mixed> */
    public function decrypt(string $value): array
    {
        $payload = base64_decode($value, true);
        if (false === $payload || !str_starts_with($payload, "FJ1\0") || strlen($payload) < 32) {
            throw new \RuntimeException('Encrypted payout details are invalid.');
        }
        $iv = substr($payload, 4, 12);
        $tag = substr($payload, 16, 16);
        $ciphertext = substr($payload, 32);
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if (false === $plaintext) {
            throw new \RuntimeException('Encrypted payout details could not be decrypted.');
        }
        $decoded = json_decode($plaintext, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Encrypted payout details are invalid.');
        }

        return $decoded;
    }
}
