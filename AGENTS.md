# adapter-discord

Discord adapter for bootdesk/chat-sdk-core. Namespace: `BootDesk\ChatSDK\Discord`

## files
- `DiscordAdapter` — implements `Adapter` using Discord REST API (createMessage, editMessage, etc.)
- `DiscordFormatConverter` — Discord markdown ↔ CommonMark AST
- `DiscordCards` — Card model → Discord Embed + Component rows
- `DiscordWebhookVerifier` — Ed25519 signature verification (Nacl)

## registration
`src/register.php` registers `'discord' => DiscordAdapter::class` via `AdapterRegistry`

## constructor
```php
new DiscordAdapter(
    string $botToken,
    string $applicationId,
    string $publicKey,
    ClientInterface $httpClient,
    ?Psr17Factory $psrFactory = null,
);
```

## thread ID format
`discord:{channelId}:{messageId}` — e.g. `discord:123456789:987654321`

## webhook flow
1. `verifyWebhook` — verifies Ed25519 signature from `X-Signature-Ed25519` + `X-Signature-Timestamp` headers; handles `PING` interaction
2. `parseWebhook` — handles MESSAGE_CREATE events and Application Command interactions

## features
- Post/edit/delete messages, embeds, components (buttons, select menus)
- Add/remove reactions
- Typing indicators (POST /channels/{id}/typing)
- Fetch channel messages, channel info
- Open DM (create DM channel)
- Slash commands via Interactions API (APPLICATION_COMMAND)
- File uploads via multipart `files[N]` with `payload_json`
- URL-based attachments rendered as embed images/links
- Inbound attachment extraction from gateway/webhook events
- Streaming: edits a single message in-place (updates gradually)

## config (laravel)
```php
'discord' => [
    'bot_token' => env('DISCORD_BOT_TOKEN'),
    'application_id' => env('DISCORD_APPLICATION_ID'),
    'public_key' => env('DISCORD_PUBLIC_KEY'),
],
```
