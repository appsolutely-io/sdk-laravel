<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel\Tests\Support;

use Firebase\JWT\JWT;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * A freshly generated key pair standing in for one the provider publishes.
 */
final readonly class SigningKey
{
    /**
     * @param array<string, string> $jwk
     */
    private function __construct(
        public string $kid,
        public string $alg,
        private OpenSSLAsymmetricKey $private,
        public array $jwk,
    ) {}

    public static function rsa(string $kid): self
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        if ($key === false) {
            throw new RuntimeException('Could not generate an RSA key.');
        }
        $details = self::details($key);
        $rsa = $details['rsa'] ?? null;
        if (!is_array($rsa) || !is_string($rsa['n'] ?? null) || !is_string($rsa['e'] ?? null)) {
            throw new RuntimeException('Unexpected RSA key details.');
        }

        return new self($kid, 'RS256', $key, [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $kid,
            'n' => JWT::urlsafeB64Encode($rsa['n']),
            'e' => JWT::urlsafeB64Encode($rsa['e']),
        ]);
    }

    public static function ec(string $kid): self
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false) {
            throw new RuntimeException('Could not generate an EC key.');
        }
        $details = self::details($key);
        $ec = $details['ec'] ?? null;
        if (!is_array($ec) || !is_string($ec['x'] ?? null) || !is_string($ec['y'] ?? null)) {
            throw new RuntimeException('Unexpected EC key details.');
        }

        return new self($kid, 'ES256', $key, [
            'kty' => 'EC',
            'use' => 'sig',
            'alg' => 'ES256',
            'kid' => $kid,
            'crv' => 'P-256',
            'x' => JWT::urlsafeB64Encode(str_pad($ec['x'], 32, "\0", STR_PAD_LEFT)),
            'y' => JWT::urlsafeB64Encode(str_pad($ec['y'], 32, "\0", STR_PAD_LEFT)),
        ]);
    }

    /**
     * @param array<string, mixed> $claims
     * @param array<string, string> $header
     */
    public function sign(array $claims, ?string $alg = null, ?string $kid = null, array $header = []): string
    {
        return JWT::encode($claims, $this->private, $alg ?? $this->alg, $kid ?? $this->kid, $header);
    }

    /**
     * @return array<mixed>
     */
    private static function details(OpenSSLAsymmetricKey $key): array
    {
        $details = openssl_pkey_get_details($key);
        if ($details === false) {
            throw new RuntimeException('Could not read key details.');
        }

        return $details;
    }
}
