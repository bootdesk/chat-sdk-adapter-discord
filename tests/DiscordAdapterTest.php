<?php

namespace BootDesk\ChatSDK\Discord\Tests;

use BootDesk\ChatSDK\Core\Cards\Button;
use BootDesk\ChatSDK\Core\Cards\Card;
use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Exceptions\AuthenticationException;
use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\Discord\DiscordAdapter;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class DiscordAdapterTest extends TestCase
{
    private DiscordAdapter $adapter;

    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory;

        $mockClient = new class implements ClientInterface
        {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $factory = new Psr17Factory;
                $uri = (string) $request->getUri();
                $method = $request->getMethod();

                // POST /channels/{id}/messages → create message
                if ($method === 'POST' && preg_match('#/channels/\w+/messages$#', $uri)) {
                    return $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode(['id' => '999888', 'timestamp' => '2024-01-01T00:00:00Z']))
                    );
                }

                // PATCH /channels/{id}/messages/{id} → edit message
                if ($method === 'PATCH' && preg_match('#/channels/\w+/messages/\w+$#', $uri)) {
                    return $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode(['id' => '999888']))
                    );
                }

                // DELETE any → 204 empty
                if ($method === 'DELETE') {
                    return $factory->createResponse(204);
                }

                // PUT reactions → 204
                if ($method === 'PUT' && str_contains($uri, '/reactions/')) {
                    return $factory->createResponse(204);
                }

                // POST typing → 204
                if ($method === 'POST' && str_contains($uri, '/typing')) {
                    return $factory->createResponse(204);
                }

                // GET messages? → message list
                if ($method === 'GET' && str_contains($uri, '/messages?')) {
                    return $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode([
                            ['id' => '111', 'content' => 'Hello', 'author' => ['id' => 'U1', 'bot' => false]],
                            ['id' => '222', 'content' => 'World', 'author' => ['id' => 'U2', 'bot' => true]],
                        ]))
                    );
                }

                // GET /channels/{id} → channel info (no /messages in URI)
                if ($method === 'GET' && preg_match('#/channels/\w+$#', $uri)) {
                    return $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode([
                            'id' => 'C123',
                            'name' => 'general',
                            'topic' => 'Chat here',
                            'type' => 0,
                            'parent_id' => 'P999',
                            'message_count' => 42,
                        ]))
                    );
                }

                // GET /users/{id}
                if ($method === 'GET' && preg_match('#/users/\w+$#', $uri)) {
                    return $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode([
                            'id' => 'U123',
                            'username' => 'johndoe',
                            'global_name' => 'John Doe',
                        ]))
                    );
                }

                // POST /users/@me/channels → open DM
                if ($method === 'POST' && str_contains($uri, '/users/@me/channels')) {
                    return $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode(['id' => 'D999']))
                    );
                }

                return $factory->createResponse(200)->withBody(
                    $factory->createStream(json_encode(['id' => 'fallback']))
                );
            }
        };

        $this->adapter = new DiscordAdapter(
            botToken: 'test-bot-token',
            publicKey: str_repeat('a', 64),
            applicationId: 'APP123',
            httpClient: $mockClient,
            psrFactory: $this->factory,
        );
    }

    public function test_get_name(): void
    {
        $this->assertSame('discord', $this->adapter->getName());
    }

    public function test_thread_id_encode_channel_only(): void
    {
        $id = $this->adapter->encodeThreadId(['channelId' => 'C123', 'guildId' => 'G456']);
        $this->assertSame('discord:G456:C123', $id);
    }

    public function test_thread_id_encode_with_thread(): void
    {
        $id = $this->adapter->encodeThreadId([
            'channelId' => 'C123',
            'guildId' => 'G456',
            'threadId' => 'T789',
        ]);
        $this->assertSame('discord:G456:C123:T789', $id);
    }

    public function test_thread_id_decode_channel_only(): void
    {
        $decoded = $this->adapter->decodeThreadId('discord:G456:C123');
        $this->assertSame('G456', $decoded['guildId']);
        $this->assertSame('C123', $decoded['channelId']);
        $this->assertNull($decoded['threadId']);
    }

    public function test_thread_id_decode_with_thread(): void
    {
        $decoded = $this->adapter->decodeThreadId('discord:G456:C123:T789');
        $this->assertSame('G456', $decoded['guildId']);
        $this->assertSame('C123', $decoded['channelId']);
        $this->assertSame('T789', $decoded['threadId']);
    }

    public function test_channel_id_from_thread_returns_thread_id(): void
    {
        $this->assertSame('T789', $this->adapter->channelIdFromThreadId('discord:G456:C123:T789'));
    }

    public function test_channel_id_from_thread_returns_channel_when_no_thread(): void
    {
        $this->assertSame('C123', $this->adapter->channelIdFromThreadId('discord:G456:C123'));
    }

    public function test_ping_pong_response(): void
    {
        $body = json_encode(['type' => 1]);
        $request = $this->factory->createServerRequest('POST', '/webhooks/discord')
            ->withBody($this->factory->createStream($body));

        $response = $this->adapter->verifyWebhook($request);

        $this->assertNotNull($response);
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame(1, $data['type']);
    }

    public function test_parse_webhook_command_interaction(): void
    {
        $this->adapter->initialize($this->createMock(Chat::class));

        $body = json_encode([
            'id' => 'INT001',
            'type' => 2,
            'channel_id' => 'C123',
            'guild_id' => 'G456',
            'member' => [
                'user' => [
                    'id' => 'U789',
                    'username' => 'testuser',
                    'global_name' => 'Test User',
                ],
            ],
            'data' => [
                'options' => [['value' => 'hello world']],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/discord')
            ->withBody($this->factory->createStream($body));

        $message = $this->adapter->parseWebhook($request);

        $this->assertSame('INT001', $message->id);
        $this->assertSame('discord:G456:C123', $message->threadId);
        $this->assertSame('U789', $message->author->id);
        $this->assertSame('Test User', $message->author->name);
        $this->assertSame('hello world', $message->text);
        $this->assertFalse($message->isDM);
    }

    public function test_parse_dm_interaction(): void
    {
        $body = json_encode([
            'id' => 'INT002',
            'type' => 2,
            'channel_id' => 'D123',
            'user' => [
                'id' => 'U789',
                'username' => 'testuser',
            ],
            'data' => ['options' => [['value' => 'dm msg']]],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/discord')
            ->withBody($this->factory->createStream($body));

        $message = $this->adapter->parseWebhook($request);
        $this->assertTrue($message->isDM);
    }

    public function test_parse_bot_message(): void
    {
        $body = json_encode([
            'id' => 'INT004',
            'type' => 2,
            'channel_id' => 'C123',
            'guild_id' => 'G456',
            'member' => [
                'user' => [
                    'id' => 'BOT999',
                    'username' => 'mybot',
                    'bot' => true,
                ],
            ],
            'data' => ['options' => [['value' => 'auto reply']]],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/discord')
            ->withBody($this->factory->createStream($body));

        $message = $this->adapter->parseWebhook($request);
        $this->assertTrue($message->author->isBot);
        $this->assertSame('BOT999', $message->author->id);
    }

    public function test_edit_message_in_thread(): void
    {
        $sent = $this->adapter->editMessage(
            'discord:G456:C123:T789',
            '999888',
            PostableMessage::text('Updated in thread'),
        );

        $this->assertSame('999888', $sent->id);
    }

    public function test_delete_message_in_thread(): void
    {
        $this->adapter->deleteMessage('discord:G456:C123:T789', '999888');
        $this->assertTrue(true);
    }

    public function test_add_reaction_in_thread(): void
    {
        $this->adapter->addReaction('discord:G456:C123:T789', '999888', '👍');
        $this->assertTrue(true);
    }

    public function test_remove_reaction_in_thread(): void
    {
        $this->adapter->removeReaction('discord:G456:C123:T789', '999888', '👍');
        $this->assertTrue(true);
    }

    public function test_start_typing_in_thread(): void
    {
        $this->adapter->startTyping('discord:G456:C123:T789');
        $this->assertTrue(true);
    }

    public function test_parse_slash_command_detects_application_command(): void
    {
        $body = json_encode([
            'type' => 2,
            'data' => ['name' => 'test'],
            'channel_id' => 'C123',
            'guild_id' => 'G456',
            'member' => [
                'user' => ['id' => 'U789', 'username' => 'testuser'],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/discord')
            ->withBody($this->factory->createStream($body));

        $result = $this->adapter->parseSlashCommand($request);

        $this->assertNotNull($result);
        $this->assertSame('/test', $result['command']);
        $this->assertSame('U789', $result['userId']);
        $this->assertSame('discord:G456:C123', $result['channelId']);
    }

    public function test_parse_slash_command_returns_null_for_ping(): void
    {
        $body = json_encode(['type' => 1]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/discord')
            ->withBody($this->factory->createStream($body));

        $this->assertNull($this->adapter->parseSlashCommand($request));
    }

    public function test_parse_slash_command_returns_null_for_component(): void
    {
        $body = json_encode([
            'type' => 3,
            'data' => ['custom_id' => 'btn_ok'],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/discord')
            ->withBody($this->factory->createStream($body));

        $this->assertNull($this->adapter->parseSlashCommand($request));
    }

    public function test_parse_slash_command_extracts_options_as_text(): void
    {
        $body = json_encode([
            'type' => 2,
            'data' => [
                'name' => 'echo',
                'options' => [
                    ['name' => 'message', 'type' => 3, 'value' => 'Hello world'],
                ],
            ],
            'channel_id' => 'C123',
            'guild_id' => 'G456',
            'member' => [
                'user' => ['id' => 'U1', 'username' => 'user'],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/discord')
            ->withBody($this->factory->createStream($body));

        $result = $this->adapter->parseSlashCommand($request);

        $this->assertNotNull($result);
        $this->assertSame('/echo', $result['command']);
        $this->assertSame('Hello world', $result['text']);
    }

    public function test_parse_slash_command_expands_subcommand_path(): void
    {
        $body = json_encode([
            'type' => 2,
            'data' => [
                'name' => 'project',
                'options' => [
                    [
                        'name' => 'issue',
                        'type' => 2,
                        'options' => [
                            [
                                'name' => 'create',
                                'type' => 1,
                                'options' => [
                                    ['name' => 'title', 'type' => 3, 'value' => 'Login fails'],
                                    ['name' => 'priority', 'type' => 3, 'value' => 'high'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'channel_id' => 'C123',
            'guild_id' => 'G456',
            'member' => [
                'user' => ['id' => 'U1', 'username' => 'user'],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/discord')
            ->withBody($this->factory->createStream($body));

        $result = $this->adapter->parseSlashCommand($request);

        $this->assertNotNull($result);
        $this->assertSame('/project issue create', $result['command']);
        $this->assertSame('Login fails high', $result['text']);
    }

    public function test_parse_slash_command_in_thread(): void
    {
        $body = json_encode([
            'type' => 2,
            'data' => ['name' => 'status'],
            'channel_id' => 'T123',
            'channel' => ['id' => 'T123', 'type' => 11, 'parent_id' => 'P456'],
            'guild_id' => 'G789',
            'member' => [
                'user' => ['id' => 'U1', 'username' => 'user'],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/discord')
            ->withBody($this->factory->createStream($body));

        $result = $this->adapter->parseSlashCommand($request);

        $this->assertNotNull($result);
        $this->assertSame('/status', $result['command']);
        $this->assertSame('discord:G789:P456:T123', $result['channelId']);
    }

    public function test_parse_component_interaction(): void
    {
        $customId = "deploy\n\"staging\"";

        $body = json_encode([
            'id' => 'INT003',
            'type' => 3,
            'channel_id' => 'C123',
            'guild_id' => 'G456',
            'member' => [
                'user' => ['id' => 'U1', 'username' => 'user1', 'global_name' => 'User One'],
            ],
            'data' => ['custom_id' => $customId],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/discord')
            ->withBody($this->factory->createStream($body));

        $message = $this->adapter->parseWebhook($request);

        $this->assertSame('INT003', $message->id);
        $this->assertStringContainsString('deploy', $message->text);
    }

    public function test_post_message(): void
    {
        $sent = $this->adapter->postMessage(
            'discord:G456:C123',
            PostableMessage::text('Hello Discord')
        );

        $this->assertSame('999888', $sent->id);
        $this->assertSame('discord:G456:C123', $sent->threadId);
        $this->assertSame('2024-01-01T00:00:00Z', $sent->timestamp);
    }

    public function test_post_message_with_thread(): void
    {
        $sent = $this->adapter->postMessage(
            'discord:G456:C123:T789',
            PostableMessage::text('Thread msg')
        );

        $this->assertSame('999888', $sent->id);
    }

    public function test_post_message_truncates_long_text(): void
    {
        $longText = str_repeat('a', 2500);
        $this->adapter->postMessage('discord:G456:C123', PostableMessage::text($longText));
        $this->assertTrue(true);
    }

    public function test_edit_message(): void
    {
        $sent = $this->adapter->editMessage(
            'discord:G456:C123',
            'MSG001',
            PostableMessage::text('Updated')
        );

        $this->assertSame('999888', $sent->id);
    }

    public function test_delete_message(): void
    {
        $this->adapter->deleteMessage('discord:G456:C123', 'MSG001');
        $this->assertTrue(true);
    }

    public function test_add_reaction(): void
    {
        $this->adapter->addReaction('discord:G456:C123', 'MSG001', '👍');
        $this->assertTrue(true);
    }

    public function test_remove_reaction(): void
    {
        $this->adapter->removeReaction('discord:G456:C123', 'MSG001', '👍');
        $this->assertTrue(true);
    }

    public function test_start_typing(): void
    {
        $this->adapter->startTyping('discord:G456:C123');
        $this->assertTrue(true);
    }

    public function test_fetch_messages(): void
    {
        $result = $this->adapter->fetchMessages('discord:G456:C123');

        $this->assertCount(2, $result->messages);
        $this->assertSame('111', $result->messages[0]->id);
        $this->assertSame('Hello', $result->messages[0]->text);
        $this->assertSame('U1', $result->messages[0]->author->id);
        $this->assertFalse($result->messages[0]->author->isBot);
        $this->assertTrue($result->messages[1]->author->isBot);
    }

    public function test_fetch_thread(): void
    {
        $info = $this->adapter->fetchThread('discord:G456:C123');

        $this->assertSame('discord:G456:C123', $info->id);
        $this->assertSame('P999', $info->channelId);
        $this->assertSame(42, $info->messageCount);
    }

    public function test_fetch_channel_info(): void
    {
        $info = $this->adapter->fetchChannelInfo('C123');

        $this->assertSame('C123', $info->id);
        $this->assertSame('general', $info->name);
        $this->assertSame('Chat here', $info->topic);
        $this->assertFalse($info->isPrivate);
    }

    public function test_get_user(): void
    {
        $user = $this->adapter->getUser('U123');

        $this->assertSame('U123', $user->id);
        $this->assertSame('John Doe', $user->name);
    }

    public function test_open_dm(): void
    {
        $threadId = $this->adapter->openDM('U123');

        $this->assertSame('discord:@me:D999', $threadId);
    }

    public function test_get_format_converter(): void
    {
        $this->assertNotNull($this->adapter->getFormatConverter());
    }

    public function test_initialize_sets_bot_user_id(): void
    {
        $chat = $this->createMock(Chat::class);
        $this->adapter->initialize($chat);

        $this->assertSame('APP123', $this->adapter->getBotUserId());
    }

    public function test_post_message_with_card(): void
    {
        $card = Card::make()
            ->header('Deploy Ready')
            ->section(fn ($s) => $s->text('Build passed'))
            ->actions([Button::primary('Deploy', 'deploy')]);

        $sent = $this->adapter->postMessage(
            'discord:G456:C123',
            PostableMessage::card($card)
        );

        $this->assertSame('999888', $sent->id);
    }

    public function test_stream_collects_and_posts(): void
    {
        $sent = $this->adapter->stream(
            'discord:G456:C123',
            ['Hello ', 'Discord', '!'],
        );

        $this->assertNotNull($sent);
        $this->assertSame('999888', $sent->id);
    }

    public function test_stream_empty_returns_null(): void
    {
        $sent = $this->adapter->stream('discord:G456:C123', []);
        $this->assertNull($sent);
    }

    public function test_disconnect_is_noop(): void
    {
        $this->adapter->disconnect();
        $this->assertTrue(true);
    }

    public function test_api_call_throws_authentication_exception_on_auth_error(): void
    {
        $factory = new Psr17Factory;
        $mockClient = new class implements ClientInterface
        {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $f = new Psr17Factory;

                return $f->createResponse(401)->withBody(
                    $f->createStream(json_encode(['code' => 401, 'message' => '401: Unauthorized']))
                );
            }
        };

        $adapter = new DiscordAdapter(
            botToken: 'bad-token',
            publicKey: str_repeat('a', 64),
            applicationId: 'APP123',
            httpClient: $mockClient,
            psrFactory: $factory,
        );

        $this->expectException(AuthenticationException::class);
        $adapter->postMessage('discord:G456:C123', PostableMessage::text('test'));
    }

    // --- Fixture-based tests from discord.json ---

    public function test_fixture_gateway_mention(): void
    {
        $this->adapter->initialize($this->createMock(Chat::class));

        $fixture = json_decode(
            file_get_contents(__DIR__.'/fixtures/discord.json'),
            true
        );

        $payload = $fixture['gatewayMention'];
        $request = $this->factory->createServerRequest('POST', '/webhooks/discord')
            ->withHeader('X-Signature-Ed25519', 'sig')
            ->withHeader('X-Signature-Timestamp', (string) $payload['timestamp'])
            ->withBody($this->factory->createStream(json_encode($payload)));

        $message = $this->adapter->parseWebhook($request);

        $this->assertSame($payload['data']['id'], $message->id);
        $this->assertStringContainsString('Hey', $message->text);
        $this->assertSame('1033044521375764530', $message->author->id);
        $this->assertSame('Test User', $message->author->name);
        $this->assertFalse($message->isDM);
    }

    public function test_fixture_button_click_hello(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/fixtures/discord.json'),
            true
        );

        $payload = $fixture['buttonClickHello'];
        $request = $this->factory->createServerRequest('POST', '/webhooks/discord')
            ->withBody($this->factory->createStream(json_encode($payload)));

        $message = $this->adapter->parseWebhook($request);

        $this->assertSame($payload['id'], $message->id);
        $this->assertStringContainsString('hello', $message->text);
        // Component interactions in threads return thread as channel_id
        $this->assertSame('discord:1457468924290662599:1457536551830421524', $message->threadId);
    }

    public function test_fixture_gateway_reaction_add(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/fixtures/discord.json'),
            true
        );

        $payload = $fixture['gatewayReactionAdd'];
        $request = $this->factory->createServerRequest('POST', '/webhooks/discord')
            ->withHeader('X-Signature-Ed25519', 'sig')
            ->withHeader('X-Signature-Timestamp', (string) $payload['timestamp'])
            ->withBody($this->factory->createStream(json_encode($payload)));

        $result = $this->adapter->parseReaction($request);

        $this->assertNotNull($result);
        $this->assertSame('👍', $result['emoji']);
        $this->assertTrue($result['added']);
        $this->assertSame($payload['data']['message_id'], $result['messageId']);
        $this->assertSame('discord:1457468924290662599:1457536551830421524', $result['threadId']);
    }

    public function test_fixture_dm_button_click(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/fixtures/discord.json'),
            true
        );

        $payload = $fixture['dmButtonClick'];
        $request = $this->factory->createServerRequest('POST', '/webhooks/discord')
            ->withBody($this->factory->createStream(json_encode($payload)));

        $message = $this->adapter->parseWebhook($request);

        // DM button clicks have context=1 and channel type 1
        $this->assertSame($payload['id'], $message->id);
        $this->assertStringContainsString('dm-action', $message->text);
    }

    public function test_fixture_gateway_thread_message(): void
    {
        $this->adapter->initialize($this->createMock(Chat::class));

        $fixture = json_decode(
            file_get_contents(__DIR__.'/fixtures/discord.json'),
            true
        );

        $payload = $fixture['gatewayThreadUserHey'];
        $request = $this->factory->createServerRequest('POST', '/webhooks/discord')
            ->withHeader('X-Signature-Ed25519', 'sig')
            ->withHeader('X-Signature-Timestamp', (string) $payload['timestamp'])
            ->withBody($this->factory->createStream(json_encode($payload)));

        // Gateway message in a thread
        $message = $this->adapter->parseWebhook($request);

        $this->assertSame($payload['data']['id'], $message->id);
        $this->assertSame('Hey', $message->text);
        // Discord gateway events for thread messages don't include parent_id
        // Adapter encodes as: discord:guild:threadChannelId:threadChannelId
        $this->assertSame('discord:1457468924290662599:1457536551830421524:1457536551830421524', $message->threadId);
        $this->assertSame('Test User', $message->author->name);
    }
}
