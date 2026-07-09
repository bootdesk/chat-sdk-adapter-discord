<?php

namespace BootDesk\ChatSDK\Discord;

use BootDesk\ChatSDK\Core\Attachment;
use BootDesk\ChatSDK\Core\Author;
use BootDesk\ChatSDK\Core\ChannelInfo;
use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Contracts\Adapter;
use BootDesk\ChatSDK\Core\Contracts\CompositeInterfaces\SupportsMessageMutability;
use BootDesk\ChatSDK\Core\Contracts\FormatConverter;
use BootDesk\ChatSDK\Core\Contracts\HandlesReactions;
use BootDesk\ChatSDK\Core\Contracts\HandlesSlashCommands;
use BootDesk\ChatSDK\Core\Contracts\RequiresSyncResponse;
use BootDesk\ChatSDK\Core\Exceptions\AdapterException;
use BootDesk\ChatSDK\Core\Exceptions\AuthenticationException;
use BootDesk\ChatSDK\Core\FetchOptions;
use BootDesk\ChatSDK\Core\FetchResult;
use BootDesk\ChatSDK\Core\Message;
use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\Core\SentMessage;
use BootDesk\ChatSDK\Core\Support\EmojiResolver;
use BootDesk\ChatSDK\Core\ThreadInfo;
use BootDesk\ChatSDK\Core\UserInfo;
use Http\Message\MultipartStream\MultipartStreamBuilder;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class DiscordAdapter implements Adapter, HandlesReactions, HandlesSlashCommands, RequiresSyncResponse, SupportsMessageMutability
{
    protected ?string $botUserId = null;

    protected DiscordFormatConverter $formatConverter;

    protected ?DiscordWebhookVerifier $webhookVerifier = null;

    protected EmojiResolver $emojiResolver;

    protected readonly ?LoggerInterface $logger;

    public function __construct(
        protected readonly string $botToken,
        protected readonly ClientInterface $httpClient,
        string $publicKey,
        protected readonly string $applicationId,
        protected readonly string $apiUrl = 'https://discord.com/api/v10',
        protected readonly ?Psr17Factory $psrFactory = null,
        ?EmojiResolver $emojiResolver = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger;
        $this->formatConverter = new DiscordFormatConverter;
        $this->emojiResolver = $emojiResolver ?? EmojiResolver::default();
        $this->webhookVerifier = new DiscordWebhookVerifier($publicKey);
    }

    public function getName(): string
    {
        return 'discord';
    }

    public function getBotUserId(): ?string
    {
        return $this->botUserId;
    }

    public function verifyWebhook(ServerRequestInterface $request): ?ResponseInterface
    {
        $body = (string) $request->getBody();

        $payload = json_decode($body, true);
        if (is_array($payload) && ($payload['type'] ?? 0) === 1) {
            $factory = $this->psrFactory ?? new Psr17Factory;

            return $factory->createResponse(200)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($factory->createStream(json_encode(['type' => 1])));
        }

        $signature = $request->getHeaderLine('x-signature-ed25519');
        $timestamp = $request->getHeaderLine('x-signature-timestamp');

        if ($signature !== '' && $timestamp !== '') {
            $this->webhookVerifier->verify($body, $signature, $timestamp);
        }

        return null;
    }

    public function parseSlashCommand(ServerRequestInterface $request): ?array
    {
        $body = (string) $request->getBody();
        $interaction = json_decode($body, true);

        if ($interaction === null || ($interaction['type'] ?? 0) !== 2) {
            return null;
        }

        $commandName = $interaction['data']['name'] ?? '';
        if ($commandName === '') {
            return null;
        }

        $commandOptions = $interaction['data']['options'] ?? [];

        $commandParts = ["/{$commandName}"];
        $valueParts = [];

        $collect = function (array $items) use (&$collect, &$commandParts, &$valueParts): void {
            foreach ($items as $option) {
                if (isset($option['value'])) {
                    $valueParts[] = (string) $option['value'];

                    continue;
                }
                if (isset($option['options']) && $option['options'] !== []) {
                    $commandParts[] = $option['name'];
                    $collect($option['options']);
                }
            }
        };

        if ($commandOptions !== []) {
            $collect($commandOptions);
        }

        $command = implode(' ', $commandParts);
        $text = trim(implode(' ', $valueParts));

        $user = $interaction['member']['user'] ?? $interaction['user'] ?? [];
        $channelId = $interaction['channel_id'] ?? '';
        $guildId = $interaction['guild_id'] ?? '@me';

        $channel = $interaction['channel'] ?? [];
        $isThread = in_array($channel['type'] ?? 0, [11, 12], true);
        $parentChannelId = ($isThread && isset($channel['parent_id'])) ? $channel['parent_id'] : $channelId;

        $encodedChannelId = $this->encodeThreadId(
            $isThread
                ? ['guildId' => $guildId, 'channelId' => $parentChannelId, 'threadId' => $channelId]
                : ['guildId' => $guildId, 'channelId' => $channelId]
        );

        return [
            'author' => new Author(
                id: $user['id'] ?? '',
                name: $user['global_name'] ?? ($user['username'] ?? ''),
                isBot: $user['bot'] ?? false,
                profilePicture: $this->getAvatarUrl($user['id'] ?? null, $user['avatar'] ?? null),
            ),
            'command' => $command,
            'text' => $text,
            'userId' => $user['id'] ?? '',
            'isBot' => $user['bot'] ?? false,
            'isMe' => false,
            'channelId' => $encodedChannelId,
            'triggerId' => null,
            'raw' => $body,
        ];
    }

    public function parseReaction(ServerRequestInterface $request): ?array
    {
        $body = (string) $request->getBody();
        $interaction = json_decode($body, true);

        if (! is_array($interaction)) {
            return null;
        }

        $type = $interaction['type'] ?? '';

        if ($type !== 'GATEWAY_MESSAGE_REACTION_ADD' && $type !== 'GATEWAY_MESSAGE_REACTION_REMOVE') {
            return null;
        }

        $data = $interaction['data'] ?? [];
        $emoji = $data['emoji'] ?? [];
        $rawEmoji = $emoji['name'] ?? '';

        $channelId = $data['channel_id'] ?? '';
        $guildId = $data['guild_id'] ?? '@me';

        $threadId = $this->encodeThreadId([
            'channelId' => $channelId,
            'guildId' => $guildId,
        ]);

        $userId = $data['user_id'] ?? '';
        $memberUser = $data['member']['user'] ?? [];

        return [
            'author' => new Author(
                id: $userId,
                name: $memberUser['global_name'] ?? ($memberUser['username'] ?? null),
                isBot: $memberUser['bot'] ?? false,
                profilePicture: $this->getAvatarUrl($userId ?: null, $memberUser['avatar'] ?? null),
            ),
            'emoji' => $this->emojiResolver->fromGChat($rawEmoji),
            'rawEmoji' => $rawEmoji,
            'added' => $type === 'GATEWAY_MESSAGE_REACTION_ADD',
            'threadId' => $threadId,
            'messageId' => $data['message_id'] ?? '',
            'userId' => $userId,
            'raw' => $interaction,
            'originId' => null,
        ];
    }

    public function parseWebhook(ServerRequestInterface $request): Message
    {
        $body = (string) $request->getBody();
        $interaction = json_decode($body, true);

        if ($interaction === null) {
            $this->logger->error('[Discord] Invalid JSON payload');
            throw new AdapterException('Invalid JSON payload from Discord');
        }

        $type = $interaction['type'] ?? 0;

        // Type 3 = MESSAGE_COMPONENT, Type 4 = Gateway forwarded event
        if ($type === 3 && isset($interaction['data']['custom_id'])) {
            return $this->parseComponentInteraction($interaction, $body);
        }

        // For gateway-forwarded MESSAGE_CREATE events
        if (isset($interaction['type']) && str_starts_with($interaction['type'], 'GATEWAY_')) {
            return $this->parseGatewayEvent($interaction, $body);
        }

        // Fallback: parse as regular message interaction
        $channelId = $interaction['channel_id'] ?? '';
        $guildId = $interaction['guild_id'] ?? '@me';
        $user = $interaction['member']['user'] ?? $interaction['user'] ?? [];
        $text = $interaction['data']['options'][0]['value'] ?? '';

        $threadId = $this->encodeThreadId([
            'channelId' => $channelId,
            'guildId' => $guildId,
        ]);

        $this->logger->info('[Discord] Message parsed', [
            'channelId' => $channelId,
            'guildId' => $guildId,
            'text_preview' => mb_substr($text, 0, 100),
        ]);

        return new Message(
            id: $interaction['id'] ?? uniqid('dc_'),
            threadId: $threadId,
            author: new Author(
                id: $user['id'] ?? '',
                name: $user['global_name'] ?? ($user['username'] ?? ''),
                isBot: $user['bot'] ?? false,
                profilePicture: $this->getAvatarUrl($user['id'] ?? null, $user['avatar'] ?? null),
            ),
            text: $text,
            attachments: $this->extractAttachments($interaction['data']['resolved']['attachments'] ?? []),
            isDM: $guildId === '@me',
            raw: $body,
        );
    }

    public function encodeThreadId(mixed $platformData): string
    {
        $channelId = $platformData['channelId'] ?? '';
        $guildId = $platformData['guildId'] ?? '@me';
        $threadId = $platformData['threadId'] ?? null;

        if ($threadId !== null && $threadId !== '') {
            return "discord:{$guildId}:{$channelId}:{$threadId}";
        }

        return "discord:{$guildId}:{$channelId}";
    }

    public function decodeThreadId(string $threadId): mixed
    {
        $parts = explode(':', $threadId, 4);

        return [
            'guildId' => $parts[1] ?? '@me',
            'channelId' => $parts[2] ?? '',
            'threadId' => $parts[3] ?? null,
        ];
    }

    public function channelIdFromThreadId(string $threadId): string
    {
        $decoded = $this->decodeThreadId($threadId);

        return "discord:{$decoded['guildId']}:{$decoded['channelId']}";
    }

    public function postMessage(string $threadId, PostableMessage $message): SentMessage
    {
        $decoded = $this->decodeThreadId($threadId);
        $channelId = $decoded['threadId'] ?? $decoded['channelId'];

        $this->logger->info('[Discord] Posting message', [
            'threadId' => $threadId,
            'has_files' => $message->files !== [] ? 'yes' : 'no',
            'has_attachments' => $message->attachments !== [] ? 'yes' : 'no',
            'text_preview' => mb_substr($message->getTextContent(), 0, 100),
        ]);

        if ($message->files !== []) {
            return $this->postMessageWithFiles($channelId, $message);
        }

        // Location fallback — no native outgoing support, append maps link to text
        if ($message->attachments !== [] && $message->attachments[0]->type === 'location') {
            $att = $message->attachments[0];
            $locText = "https://www.google.com/maps?q={$att->lat},{$att->lng}";
            if ($att->name !== null) {
                $locText = $att->name."\n".$locText;
            }
            if ($att->address !== null) {
                $locText .= "\n".$att->address;
            }
            $originalText = $message->getTextContent();
            $mergedText = $originalText !== '' ? $originalText."\n\n".$locText : $locText;
            $message = new PostableMessage(
                content: $mergedText,
                replyToMessageId: $message->replyToMessageId,
            );
        }

        $params = $this->buildMessageParams($message);

        // Add file/image URLs as embeds
        foreach ($message->attachments as $att) {
            if ($att->url !== null) {
                if ($att->type === 'image') {
                    $params['embeds'][] = [
                        'image' => ['url' => $att->url],
                        'title' => $att->name ?? '',
                    ];
                } else {
                    $params['embeds'][] = [
                        'description' => "[{$att->name}](".$att->url.')',
                    ];
                }
            }
        }

        $response = $this->apiCall("/channels/{$channelId}/messages", $params);

        return new SentMessage(
            id: $response['id'] ?? '',
            threadId: $threadId,
            timestamp: $response['timestamp'] ?? null,
        );
    }

    protected function postMessageWithFiles(string $channelId, PostableMessage $message): SentMessage
    {
        $payload = $this->buildMessageParams($message);

        $builder = new MultipartStreamBuilder($this->psrFactory ?? new Psr17Factory);

        $builder->addData(json_encode($payload), [
            'Content-Disposition' => 'form-data; name="payload_json"',
            'Content-Type' => 'application/json',
        ]);

        foreach ($message->files as $i => $file) {
            $builder->addResource("files[{$i}]", $file->data, [
                'filename' => $file->filename,
                'headers' => ['Content-Type' => $file->mimeType ?? 'application/octet-stream'],
            ]);
        }

        $stream = $builder->build();
        $boundary = $builder->getBoundary();

        $factory = $this->psrFactory ?? new Psr17Factory;
        $url = "{$this->apiUrl}/channels/{$channelId}/messages";
        $request = $factory->createRequest('POST', $url)
            ->withHeader('Authorization', "Bot {$this->botToken}")
            ->withHeader('Content-Type', "multipart/form-data; boundary={$boundary}")
            ->withBody($stream);

        $psrResponse = $this->httpClient->sendRequest($request);
        $responseBody = (string) $psrResponse->getBody();
        $data = json_decode($responseBody, true);

        if (! is_array($data)) {
            throw new AdapterException('Invalid JSON response from Discord API');
        }

        if (isset($data['code']) && $data['code'] !== 200) {
            $error = $data['message'] ?? $data['code'];
            throw new AdapterException("Discord API error: {$error}");
        }

        return new SentMessage(
            id: $data['id'] ?? '',
            threadId: "discord:{$channelId}",
            timestamp: $data['timestamp'] ?? null,
        );
    }

    public function editMessage(string $threadId, string $messageId, PostableMessage $message): SentMessage
    {
        $decoded = $this->decodeThreadId($threadId);
        $channelId = $decoded['threadId'] ?? $decoded['channelId'];

        $params = $this->buildMessageParams($message);

        $response = $this->apiCall("/channels/{$channelId}/messages/{$messageId}", $params, 'PATCH');

        return new SentMessage(
            id: $response['id'] ?? $messageId,
            threadId: $threadId,
        );
    }

    public function deleteMessage(string $threadId, string $messageId): void
    {
        $decoded = $this->decodeThreadId($threadId);
        $channelId = $decoded['threadId'] ?? $decoded['channelId'];

        $this->apiCall("/channels/{$channelId}/messages/{$messageId}", [], 'DELETE');
    }

    public function addReaction(string $threadId, string $messageId, string $emoji): void
    {
        $decoded = $this->decodeThreadId($threadId);
        $channelId = $decoded['threadId'] ?? $decoded['channelId'];
        $encodedEmoji = urlencode($this->emojiResolver->toDiscord($emoji));

        $this->apiCall("/channels/{$channelId}/messages/{$messageId}/reactions/{$encodedEmoji}/@me", [], 'PUT');
    }

    public function removeReaction(string $threadId, string $messageId, string $emoji): void
    {
        $decoded = $this->decodeThreadId($threadId);
        $channelId = $decoded['threadId'] ?? $decoded['channelId'];
        $encodedEmoji = urlencode($this->emojiResolver->toDiscord($emoji));

        $this->apiCall("/channels/{$channelId}/messages/{$messageId}/reactions/{$encodedEmoji}/@me", [], 'DELETE');
    }

    public function startTyping(string $threadId): void
    {
        $decoded = $this->decodeThreadId($threadId);
        $channelId = $decoded['threadId'] ?? $decoded['channelId'];

        $this->apiCall("/channels/{$channelId}/typing", [], 'POST');
    }

    public function fetchMessages(string $threadId, ?FetchOptions $options = null): FetchResult
    {
        $decoded = $this->decodeThreadId($threadId);
        $channelId = $decoded['threadId'] ?? $decoded['channelId'];

        $params = ['limit' => $options->limit ?? 50];

        $response = $this->apiCall("/channels/{$channelId}/messages?".http_build_query($params), [], 'GET');

        $messages = [];
        foreach ($response as $msg) {
            $messages[] = new Message(
                id: $msg['id'],
                threadId: $threadId,
                author: new Author(
                    id: $msg['author']['id'] ?? '',
                    isBot: $msg['author']['bot'] ?? false,
                    profilePicture: $this->getAvatarUrl($msg['author']['id'] ?? null, $msg['author']['avatar'] ?? null),
                ),
                text: $msg['content'] ?? '',
            );
        }

        return new FetchResult(messages: $messages);
    }

    public function fetchThread(string $threadId): ThreadInfo
    {
        $decoded = $this->decodeThreadId($threadId);
        $channelId = $decoded['threadId'] ?? $decoded['channelId'];

        $response = $this->apiCall("/channels/{$channelId}", [], 'GET');

        return new ThreadInfo(
            id: $threadId,
            channelId: $response['parent_id'] ?? $channelId,
            title: $response['name'] ?? null,
            messageCount: $response['message_count'] ?? 0,
            topic: $response['topic'] ?? null,
            isArchived: $response['thread_metadata']['archived'] ?? null,
        );
    }

    public function editThread(string $threadId, ThreadInfo $threadInfo): ThreadInfo
    {
        $decoded = $this->decodeThreadId($threadId);
        $channelId = $decoded['threadId'] ?? $decoded['channelId'];

        $params = [];

        if ($threadInfo->title !== null) {
            $params['name'] = $threadInfo->title;
        }

        if ($threadInfo->topic !== null) {
            $params['topic'] = $threadInfo->topic;
        }

        if ($threadInfo->isArchived !== null) {
            $params['archived'] = $threadInfo->isArchived;
        }

        if ($params !== []) {
            $this->apiCall("/channels/{$channelId}", $params, 'PATCH');
        }

        return $this->fetchThread($threadId);
    }

    public function fetchChannelInfo(string $channelId): ?ChannelInfo
    {
        $parts = explode(':', $channelId, 4);
        $discordChannelId = $parts[3] ?? $parts[2] ?? '';

        if ($discordChannelId === '') {
            return null;
        }

        $response = $this->apiCall("/channels/{$discordChannelId}", [], 'GET');

        return new ChannelInfo(
            id: $channelId,
            name: $response['name'] ?? '',
            topic: $response['topic'] ?? null,
            isPrivate: ($response['type'] ?? 0) === 1,
        );
    }

    public function getUser(string $userId): ?UserInfo
    {
        $response = $this->apiCall("/users/{$userId}", [], 'GET');

        return new UserInfo(
            id: $response['id'],
            name: $response['global_name'] ?? ($response['username'] ?? ''),
        );
    }

    public function openDM(string $userId): ?string
    {
        $response = $this->apiCall('/users/@me/channels', [
            'recipient_id' => $userId,
        ]);

        $channelId = $response['id'] ?? null;

        if ($channelId === null) {
            return null;
        }

        return $this->encodeThreadId([
            'channelId' => $channelId,
            'guildId' => '@me',
        ]);
    }

    public function getFormatConverter(): ?FormatConverter
    {
        return $this->formatConverter;
    }

    public function initialize(Chat $chat): void
    {
        $this->botUserId = $this->applicationId;
    }

    public function disconnect(): void
    {
        // No persistent connection
    }

    public function createResponse(): ?ResponseInterface
    {
        return null;
    }

    public function stream(string $threadId, iterable $textStream, array $options = []): ?SentMessage
    {
        $fullText = '';
        foreach ($textStream as $chunk) {
            $fullText .= $chunk;
        }

        if ($fullText === '') {
            return null;
        }

        return $this->postMessage($threadId, PostableMessage::text($fullText));
    }

    protected function parseComponentInteraction(array $interaction, string $rawBody): Message
    {
        $customId = $interaction['data']['custom_id'] ?? '';
        $decoded = DiscordCards::decodeCustomId($customId);

        $channelId = $interaction['channel_id'] ?? '';
        $guildId = $interaction['guild_id'] ?? '@me';
        $user = $interaction['member']['user'] ?? $interaction['user'] ?? [];

        $threadId = $this->encodeThreadId([
            'channelId' => $channelId,
            'guildId' => $guildId,
        ]);

        return new Message(
            id: $interaction['id'] ?? '',
            threadId: $threadId,
            author: new Author(
                id: $user['id'] ?? '',
                name: $user['global_name'] ?? ($user['username'] ?? ''),
                isBot: $user['bot'] ?? false,
                profilePicture: $this->getAvatarUrl($user['id'] ?? null, $user['avatar'] ?? null),
            ),
            text: $decoded['actionId'].($decoded['value'] ? ": {$decoded['value']}" : ''),
            raw: $rawBody,
        );
    }

    protected function parseGatewayEvent(array $event, string $rawBody): Message
    {
        $data = $event['data'] ?? [];

        $channelId = $data['channel_id'] ?? '';
        $guildId = $data['guild_id'] ?? '@me';
        $author = $data['author'] ?? [];
        $text = $data['content'] ?? '';

        $threadInfo = $data['thread'] ?? null;

        $threadId = $this->encodeThreadId([
            'channelId' => $threadInfo['parent_id'] ?? $channelId,
            'guildId' => $guildId,
            'threadId' => $threadInfo['id'] ?? (($data['channel_type'] ?? 0) >= 11 ? $channelId : null),
        ]);

        $isMention = $data['is_mention'] ?? false;

        $attachments = $this->extractAttachments($data['attachments'] ?? []);

        foreach ($data['sticker_items'] ?? [] as $sticker) {
            $attachments[] = new Attachment(
                type: 'sticker',
                name: $sticker['name'] ?? null,
                mimeType: 'image/png',
                fetchMetadata: [
                    'sticker_id' => $sticker['id'] ?? null,
                    'format_type' => $sticker['format_type'] ?? null,
                ],
            );
        }

        foreach ($data['embeds'] ?? [] as $embed) {
            $embedUrl = $embed['url'] ?? null;
            $embedTitle = $embed['title'] ?? null;

            if (isset($embed['image']['url'])) {
                $attachments[] = new Attachment(
                    type: 'image',
                    url: $embed['image']['url'],
                    name: $embedTitle,
                );
            } elseif (isset($embed['video']['url'])) {
                $attachments[] = new Attachment(
                    type: 'video',
                    url: $embed['video']['url'],
                    name: $embedTitle,
                );
            } elseif (isset($embed['thumbnail']['url'])) {
                $attachments[] = new Attachment(
                    type: 'image',
                    url: $embed['thumbnail']['url'],
                    name: $embedTitle,
                );
            } elseif ($embedUrl !== null) {
                $attachments[] = new Attachment(
                    type: 'embed',
                    url: $embedUrl,
                    name: $embedTitle,
                );
            }
        }

        return new Message(
            id: $data['id'] ?? uniqid('dc_'),
            threadId: $threadId,
            author: new Author(
                id: $author['id'] ?? '',
                name: $author['global_name'] ?? ($author['username'] ?? ''),
                isBot: $author['bot'] ?? false,
                profilePicture: $this->getAvatarUrl($author['id'] ?? null, $author['avatar'] ?? null),
            ),
            text: $text,
            attachments: $attachments,
            isMention: $isMention,
            isDM: $guildId === '@me',
            raw: $rawBody,
        );
    }

    /** @return Attachment[] */
    protected function extractAttachments(array $attachments): array
    {
        $result = [];
        foreach ($attachments as $att) {
            $contentType = $att['content_type'] ?? $att['contentType'] ?? '';
            $type = match (true) {
                str_starts_with($contentType, 'image/') => 'image',
                str_starts_with($contentType, 'video/') => 'video',
                str_starts_with($contentType, 'audio/') => 'audio',
                default => 'file',
            };

            $result[] = new Attachment(
                type: $type,
                url: $att['url'] ?? null,
                name: $att['filename'] ?? $att['name'] ?? null,
                mimeType: $contentType ?: null,
                size: $att['size'] ?? null,
                width: $att['width'] ?? null,
                height: $att['height'] ?? null,
            );
        }

        return $result;
    }

    protected function getAttachmentType(?string $mimeType): string
    {
        if ($mimeType === null) {
            return 'file';
        }
        if (str_starts_with($mimeType, 'image/')) {
            return 'image';
        }
        if (str_starts_with($mimeType, 'video/')) {
            return 'video';
        }
        if (str_starts_with($mimeType, 'audio/')) {
            return 'audio';
        }

        return 'file';
    }

    protected function buildMessageParams(PostableMessage $message): array
    {
        if ($message->isCard()) {
            $payload = DiscordCards::toDiscordPayload($message->content);

            return [
                'content' => $message->content->getFallbackText(),
                'embeds' => $payload['embeds'],
                'components' => $payload['components'],
            ];
        }

        $content = $this->formatConverter->convertMentionsToDiscord((string) $message->content);

        if (strlen($content) > 2000) {
            $content = substr($content, 0, 1997).'...';
        }

        return ['content' => $content];
    }

    protected function getAvatarUrl(?string $userId, ?string $avatarHash): ?string
    {
        if ($avatarHash === null || $avatarHash === '' || $userId === null || $userId === '') {
            return null;
        }

        $ext = str_starts_with($avatarHash, 'a_') ? 'gif' : 'webp';

        return "https://cdn.discordapp.com/avatars/{$userId}/{$avatarHash}.{$ext}?size=1024";
    }

    protected function apiCall(string $endpoint, array $params, string $method = 'POST'): array
    {
        $factory = $this->psrFactory ?? new Psr17Factory;
        $url = "{$this->apiUrl}{$endpoint}";

        $this->logger->debug('[Discord] API call', [
            'method' => $method,
            'url' => $url,
        ]);

        if ($method === 'GET') {
            $request = $factory->createRequest('GET', $url)
                ->withHeader('Authorization', "Bot {$this->botToken}");
        } else {
            $body = json_encode(array_filter($params, fn ($v): bool => $v !== null));
            $request = $factory->createRequest($method, $url)
                ->withHeader('Authorization', "Bot {$this->botToken}")
                ->withHeader('Content-Type', 'application/json')
                ->withBody($factory->createStream($body));
        }

        $psrResponse = $this->httpClient->sendRequest($request);
        $responseBody = (string) $psrResponse->getBody();

        $data = json_decode($responseBody, true);

        if ($data === null) {
            return [];
        }

        if (! is_array($data)) {
            throw new AdapterException("Invalid JSON response from Discord API: {$endpoint}");
        }

        if (isset($data['code']) && $data['code'] !== 200) {
            $error = $data['message'] ?? $data['code'];

            if (in_array($data['code'], [401, 403], true)) {
                $this->logger->error('[Discord] API auth error', [
                    'endpoint' => $endpoint,
                    'code' => $data['code'],
                    'error' => $error,
                ]);
                throw new AuthenticationException("Discord API authentication error ({$endpoint}): {$error}");
            }

            $this->logger->error('[Discord] API error', [
                'endpoint' => $endpoint,
                'code' => $data['code'],
                'error' => $error,
            ]);
            throw new AdapterException("Discord API error ({$endpoint}): {$error}");
        }

        return $data;
    }
}
