<?php

namespace BootDesk\ChatSDK\Discord\Tests;

use BootDesk\ChatSDK\Discord\DiscordFormatConverter;
use PHPUnit\Framework\TestCase;

class DiscordFormatConverterTest extends TestCase
{
    private DiscordFormatConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new DiscordFormatConverter;
    }

    public function test_user_mention_to_ast(): void
    {
        $ast = $this->converter->toAst('Hello <@123456789>');
        $markdown = $this->converter->fromAst($ast);
        $this->assertStringContainsString('123456789', $markdown);
    }

    public function test_user_mention_with_nickname(): void
    {
        $ast = $this->converter->toAst('Hello <@!123456789>');
        $markdown = $this->converter->fromAst($ast);
        $this->assertStringContainsString('123456789', $markdown);
    }

    public function test_channel_mention(): void
    {
        $ast = $this->converter->toAst('Posted in <#987654321>');
        $markdown = $this->converter->fromAst($ast);
        $this->assertStringContainsString('987654321', $markdown);
    }

    public function test_role_mention(): void
    {
        $ast = $this->converter->toAst('Ping <@&555555555>');
        $markdown = $this->converter->fromAst($ast);
        $this->assertStringContainsString('555555555', $markdown);
    }

    public function test_custom_emoji(): void
    {
        $ast = $this->converter->toAst('Nice <:thumbsup:111222333>');
        $markdown = $this->converter->fromAst($ast);
        $this->assertStringContainsString(':thumbsup:', $markdown);
    }

    public function test_animated_emoji(): void
    {
        $ast = $this->converter->toAst('Party <a:dance:444555666>');
        $markdown = $this->converter->fromAst($ast);
        $this->assertStringContainsString(':dance:', $markdown);
    }

    public function test_spoiler_tags(): void
    {
        $ast = $this->converter->toAst('This is ||secret|| text');
        $markdown = $this->converter->fromAst($ast);
        $this->assertStringContainsString('secret', $markdown);
    }

    public function test_convert_mentions_to_discord(): void
    {
        $result = $this->converter->convertMentionsToDiscord('Hello @john');
        $this->assertSame('Hello <@john>', $result);
    }

    public function test_convert_mentions_no_match(): void
    {
        $result = $this->converter->convertMentionsToDiscord('No mentions here');
        $this->assertSame('No mentions here', $result);
    }

    public function test_roundtrip(): void
    {
        $ast = $this->converter->toAst('Hello <@123> in <#456>');
        $markdown = $this->converter->fromAst($ast);
        $this->assertStringContainsString('123', $markdown);
        $this->assertStringContainsString('456', $markdown);
    }
}
