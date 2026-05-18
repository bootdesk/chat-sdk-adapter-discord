<?php

namespace BootDesk\ChatSDK\Discord\Tests;

use BootDesk\ChatSDK\Core\Exceptions\AuthenticationException;
use BootDesk\ChatSDK\Discord\DiscordWebhookVerifier;
use PHPUnit\Framework\TestCase;

class DiscordWebhookVerifierTest extends TestCase
{
    private DiscordWebhookVerifier $verifier;

    private string $publicKey;

    private string $privateKey;

    protected function setUp(): void
    {
        // Generate Ed25519 keypair for testing
        $keypair = sodium_crypto_sign_keypair();
        $this->publicKey = sodium_bin2hex(sodium_crypto_sign_publickey($keypair));
        $this->privateKey = sodium_bin2hex(sodium_crypto_sign_secretkey($keypair));

        $this->verifier = new DiscordWebhookVerifier($this->publicKey);
    }

    public function test_valid_signature_passes(): void
    {
        $body = '{"type":1}';
        $timestamp = '1234567890';
        $message = $timestamp.$body;

        $signature = sodium_bin2hex(
            sodium_crypto_sign_detached($message, sodium_hex2bin($this->privateKey))
        );

        $this->verifier->verify($body, $signature, $timestamp);
        $this->assertTrue(true); // No exception thrown
    }

    public function test_invalid_signature_throws(): void
    {
        $this->expectException(AuthenticationException::class);

        $this->verifier->verify('body', 'invalid_signature_hex', 'timestamp');
    }

    public function test_tampered_body_throws(): void
    {
        $body = '{"type":1}';
        $timestamp = '1234567890';
        $message = $timestamp.$body;

        $signature = sodium_bin2hex(
            sodium_crypto_sign_detached($message, sodium_hex2bin($this->privateKey))
        );

        $this->expectException(AuthenticationException::class);
        $this->verifier->verify('tampered body', $signature, $timestamp);
    }

    public function test_wrong_timestamp_throws(): void
    {
        $body = '{"type":1}';
        $timestamp = '1234567890';
        $message = $timestamp.$body;

        $signature = sodium_bin2hex(
            sodium_crypto_sign_detached($message, sodium_hex2bin($this->privateKey))
        );

        $this->expectException(AuthenticationException::class);
        $this->verifier->verify($body, $signature, '9999999999');
    }
}
