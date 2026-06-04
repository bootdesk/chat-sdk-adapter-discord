<?php

namespace BootDesk\ChatSDK\Discord;

use BootDesk\ChatSDK\Core\Cards\Button;
use BootDesk\ChatSDK\Core\Cards\ButtonStyle;
use BootDesk\ChatSDK\Core\Cards\Card;
use BootDesk\ChatSDK\Core\Cards\Link;
use BootDesk\ChatSDK\Core\Cards\LinkButton;
use BootDesk\ChatSDK\Core\Cards\Table;
use BootDesk\ChatSDK\Core\Cards\Text;
use BootDesk\ChatSDK\Core\Cards\TextStyle;
use BootDesk\ChatSDK\Core\Exceptions\ValidationException;

class DiscordCards
{
    public static function toDiscordPayload(Card $card): array
    {
        $embed = ['color' => 0x5865F2];
        $components = [];

        if ($card->getHeader() !== null) {
            $embed['title'] = $card->getHeader();
        }

        $descriptionParts = [];

        foreach ($card->getChildren() as $child) {
            if ($child instanceof Text) {
                $descriptionParts[] = match ($child->style) {
                    TextStyle::Bold => "**{$child->content}**",
                    TextStyle::Muted => "*{$child->content}*",
                    default => $child->content,
                };
            } elseif ($child instanceof Link) {
                $descriptionParts[] = "[{$child->label}]({$child->url})";
            } elseif ($child instanceof Table) {
                $descriptionParts[] = self::convertTableToDiscord($child);
            } elseif ($child instanceof LinkButton) {
                $components[] = [
                    'type' => 1,
                    'components' => [[
                        'type' => 2,
                        'style' => 5,
                        'label' => $child->label,
                        'url' => $child->url,
                    ]],
                ];
            }
        }

        foreach ($card->getSections() as $section) {
            if ($section->getText() !== null) {
                $descriptionParts[] = $section->getText();
            }

            if (! empty($section->getFields())) {
                $embed['fields'] = [];
                foreach ($section->getFields() as $label => $value) {
                    $embed['fields'][] = [
                        'name' => $label,
                        'value' => $value,
                        'inline' => true,
                    ];
                }
            }
        }

        if ($descriptionParts !== []) {
            $embed['description'] = implode("\n\n", $descriptionParts);
        }

        if ($card->getImageUrl() !== null) {
            $embed['image'] = ['url' => $card->getImageUrl()];
        }

        $buttons = $card->getButtons();
        if ($buttons !== []) {
            $actionRow = ['type' => 1, 'components' => []];
            foreach (array_slice($buttons, 0, 5) as $button) {
                $actionRow['components'][] = self::convertButton($button);
            }
            $components[] = $actionRow;
        }

        return [
            'embeds' => [$embed],
            'components' => $components,
        ];
    }

    public static function encodeCustomId(string $actionId, ?string $value = null): string
    {
        if ($value === null || $value === '') {
            return $actionId;
        }

        $encoded = "{$actionId}\n{$value}";

        if (strlen($encoded) > 100) {
            throw new ValidationException(
                'Discord custom_id must be 1-100 characters.'
            );
        }

        return $encoded;
    }

    public static function decodeCustomId(string $customId): array
    {
        $idx = strpos($customId, "\n");

        if ($idx === false) {
            return ['actionId' => $customId, 'value' => null];
        }

        return [
            'actionId' => substr($customId, 0, $idx),
            'value' => substr($customId, $idx + 1),
        ];
    }

    private static function convertButton(Button $button): array
    {
        $style = match ($button->style) {
            ButtonStyle::Primary => 1,
            ButtonStyle::Danger => 4,
            default => 2,
        };

        return [
            'type' => 2,
            'style' => $style,
            'label' => $button->label,
            'custom_id' => self::encodeCustomId($button->actionId, json_encode($button->data) ?: null),
        ];
    }

    private static function convertTableToDiscord(Table $table): string
    {
        return Table::renderAsText($table);
    }
}
