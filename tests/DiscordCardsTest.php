<?php

namespace BootDesk\ChatSDK\Discord\Tests;

use BootDesk\ChatSDK\Core\Cards\Button;
use BootDesk\ChatSDK\Core\Cards\Card;
use BootDesk\ChatSDK\Core\Exceptions\ValidationException;
use BootDesk\ChatSDK\Discord\DiscordCards;
use PHPUnit\Framework\TestCase;

class DiscordCardsTest extends TestCase
{
    public function test_basic_card_to_embed(): void
    {
        $card = Card::make()->header('Test Title')->section(fn ($s) => $s->text('Description here'));

        $payload = DiscordCards::toDiscordPayload($card);

        $this->assertCount(1, $payload['embeds']);
        $this->assertSame('Test Title', $payload['embeds'][0]['title']);
        $this->assertSame('Description here', $payload['embeds'][0]['description']);
    }

    public function test_card_with_fields(): void
    {
        $card = Card::make()->section(fn ($s) => $s->fields(['Status' => 'Passed', 'Env' => 'Staging']));

        $payload = DiscordCards::toDiscordPayload($card);

        $fields = $payload['embeds'][0]['fields'] ?? [];
        $this->assertCount(2, $fields);
        $this->assertSame('Status', $fields[0]['name']);
        $this->assertSame('Passed', $fields[0]['value']);
        $this->assertTrue($fields[0]['inline']);
    }

    public function test_card_with_buttons(): void
    {
        $card = Card::make()
            ->header('Deploy')
            ->actions([
                Button::primary('Deploy Now', 'deploy'),
                Button::secondary('Cancel', 'cancel'),
            ]);

        $payload = DiscordCards::toDiscordPayload($card);

        $this->assertCount(1, $payload['components']);
        $actionRow = $payload['components'][0];
        $this->assertSame(1, $actionRow['type']); // ACTION_ROW
        $this->assertCount(2, $actionRow['components']);

        $this->assertSame(2, $actionRow['components'][0]['type']); // BUTTON
        $this->assertSame(1, $actionRow['components'][0]['style']); // PRIMARY
        $this->assertSame('Deploy Now', $actionRow['components'][0]['label']);

        $this->assertSame(2, $actionRow['components'][1]['style']); // SECONDARY (default)
    }

    public function test_danger_button_style(): void
    {
        $card = Card::make()->actions([Button::danger('Delete', 'delete')]);

        $payload = DiscordCards::toDiscordPayload($card);

        $this->assertSame(4, $payload['components'][0]['components'][0]['style']); // DANGER
    }

    public function test_max_five_buttons(): void
    {
        $buttons = [];
        for ($i = 1; $i <= 7; $i++) {
            $buttons[] = Button::secondary("Btn {$i}", "action_{$i}");
        }

        $card = Card::make()->actions($buttons);
        $payload = DiscordCards::toDiscordPayload($card);

        $this->assertCount(5, $payload['components'][0]['components']);
    }

    public function test_encode_custom_id_without_value(): void
    {
        $id = DiscordCards::encodeCustomId('deploy');
        $this->assertSame('deploy', $id);
    }

    public function test_encode_custom_id_with_value(): void
    {
        $id = DiscordCards::encodeCustomId('deploy', 'staging');
        $this->assertSame("deploy\nstaging", $id);
    }

    public function test_encode_custom_id_exceeds_100_chars(): void
    {
        $this->expectException(ValidationException::class);

        DiscordCards::encodeCustomId(str_repeat('a', 50), str_repeat('b', 60));
    }

    public function test_decode_custom_id_without_value(): void
    {
        $result = DiscordCards::decodeCustomId('deploy');
        $this->assertSame('deploy', $result['actionId']);
        $this->assertNull($result['value']);
    }

    public function test_decode_custom_id_with_value(): void
    {
        $result = DiscordCards::decodeCustomId("deploy\nstaging");
        $this->assertSame('deploy', $result['actionId']);
        $this->assertSame('staging', $result['value']);
    }

    public function test_roundtrip_custom_id(): void
    {
        $encoded = DiscordCards::encodeCustomId('action', 'value123');
        $decoded = DiscordCards::decodeCustomId($encoded);

        $this->assertSame('action', $decoded['actionId']);
        $this->assertSame('value123', $decoded['value']);
    }

    public function test_embed_color_is_blurple(): void
    {
        $card = Card::make()->header('Color Test');
        $payload = DiscordCards::toDiscordPayload($card);

        $this->assertSame(0x5865F2, $payload['embeds'][0]['color']);
    }
}
