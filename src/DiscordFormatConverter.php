<?php

namespace BootDesk\ChatSDK\Discord;

use BootDesk\ChatSDK\Core\Markdown\BaseFormatConverter;
use BootDesk\ChatSDK\Core\PostableMessage;
use League\CommonMark\Node\Block\Document;

class DiscordFormatConverter extends BaseFormatConverter
{
    public function toAst(string $text): Document
    {
        // Convert Discord-specific formats to standard markdown
        $markdown = $text;

        // User mentions: <@userId> or <@!userId> -> @userId
        $markdown = preg_replace('/<@!?(\w+)>/', '@$1', $markdown);

        // Channel mentions: <#channelId> -> #channelId
        $markdown = preg_replace('/<#(\w+)>/', '#$1', $markdown);

        // Role mentions: <@&roleId> -> @&roleId
        $markdown = preg_replace('/<@&(\w+)>/', '@&$1', $markdown);

        // Custom emoji: <:name:id> or <a:name:id> -> :name:
        $markdown = preg_replace('/<a?:(\w+):\d+>/', ':$1:', $markdown);

        // Spoiler tags: ||text|| -> [spoiler: text]
        $markdown = preg_replace('/\|\|([^|]+)\|\|/', '[spoiler: $1]', $markdown);

        return $this->parseMarkdown($markdown);
    }

    public function fromAst(Document $ast): string
    {
        return $this->renderMarkdown($ast);
    }

    public function renderPostable(PostableMessage $message): string
    {
        if ($message->isCard()) {
            return $message->content->getFallbackText();
        }

        $text = (string) $message->content;

        return $this->convertMentionsToDiscord($text);
    }

    public function convertMentionsToDiscord(string $text): string
    {
        return preg_replace('/@(\w+)/', '<@$1>', $text);
    }
}
