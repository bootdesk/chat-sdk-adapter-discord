<?php

namespace BootDesk\ChatSDK\Discord;

use BootDesk\ChatSDK\Core\Exceptions\AuthenticationException;

class DiscordWebhookVerifier
{
    public function __construct(
        private readonly string $publicKey,
    ) {}

    public function verify(string $body, string $signature, string $timestamp): void
    {
        try {
            $message = $timestamp.$body;
            $signatureBytes = sodium_hex2bin($signature);
            $publicKeyBytes = sodium_hex2bin($this->publicKey);

            $valid = sodium_crypto_sign_verify_detached($signatureBytes, $message, $publicKeyBytes);
        } catch (\SodiumException) {
            throw new AuthenticationException('Invalid Discord Ed25519 signature');
        }

        if (! $valid) {
            throw new AuthenticationException('Invalid Discord Ed25519 signature');
        }
    }
}
