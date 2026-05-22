# bootdesk/chat-sdk-adapter-discord

Discord adapter for the laravel-bootdesk multi-platform messaging framework.

## Install

```bash
composer require bootdesk/chat-sdk-adapter-discord
```

Requires a PSR-18 HTTP client (`guzzlehttp/guzzle`, `symfony/http-client`, etc.) and a PSR-17 factory (`nyholm/psr7` bundled).

## Configuration

| Variable         | Description                 | Example                 |
| ---------------- | --------------------------- | ----------------------- |
| `bot_token`      | Discord Bot Token           | `MTk4NjIy...`           |
| `http_client`    | PSR-18 HTTP client instance | `new GuzzleHttp\Client` |
| `public_key`     | Application Public Key      | `abcdef123456...`       |
| `application_id` | Discord Application ID      | `1234567890`            |

```php
use BootDesk\ChatSDK\Discord\DiscordAdapter;

$adapter = new DiscordAdapter(
    botToken: env('DISCORD_BOT_TOKEN'),
    httpClient: new \GuzzleHttp\Client,
    publicKey: env('DISCORD_PUBLIC_KEY'),
    applicationId: env('DISCORD_APPLICATION_ID'),
);
```

### Laravel

The `ChatServiceProvider` auto-binds `Psr\Http\Client\ClientInterface` to `GuzzleHttp\Client`. Add to `config/chat.php`:

```php
'discord' => [
    'bot_token'      => env('DISCORD_BOT_TOKEN'),
    'public_key'     => env('DISCORD_PUBLIC_KEY'),
    'application_id' => env('DISCORD_APPLICATION_ID'),
],
```

## Quick Example

```php
// Post a message to a Discord channel
$adapter->postMessage('discord:1234567890', 'Hello from laravel-bootdesk!');

// Reply to a specific message
$adapter->postMessage('discord:1234567890:9876543210', 'Thread reply');
```

## Thread ID Format

| Format                            | Description                 |
| --------------------------------- | --------------------------- |
| `discord:{channelId}`             | Channel message             |
| `discord:{channelId}:{messageId}` | Reply to a specific message |

## Webhook

Discord sends Interactions via POST to your endpoint. Verify requests using Ed25519 signature verification with the public key.

## Feature Matrix

| Feature            | Supported |
| ------------------ | --------- |
| Post messages      | ✓         |
| Edit messages      | ✓         |
| Delete messages    | ✓         |
| Reactions          | ✓         |
| Slash commands     | ✓         |
| Typing indicator   | ✓         |
| Fetch messages     | ✓         |
| Fetch thread info  | ✓         |
| Fetch channel info | ✓         |
| Get user           | ✓         |
| Open DM            | ✗         |
| Stream             | ✓         |

## Notes

Supports slash commands, buttons, select menus, modals, and application commands.

## Documentationn

Full API documentation: https://bootdesk.github.io/chat-sdk

## License

MIT
