<?php
declare(strict_types=1);

namespace Infrastructure\Integration\Telegram;

use Longman\TelegramBot\Entities\InlineKeyboardButton;

final class InlineKeyboardFactory
{
    public static function button(string $text, string $data, string $type = 'callback_data'): InlineKeyboardButton
    {
        $field = match ($type) {
            'sc' => 'switch_inline_query_current_chat',
            's' => 'switch_inline_query',
            default => $type,
        };

        return new InlineKeyboardButton([
            'text' => defined($text) ? constant($text) : $text,
            $field => $data,
        ]);
    }

    /** @return list<InlineKeyboardButton> */
    public static function adminLine(string $type, int|string $id): array
    {
        return [
            self::button('🖋 ' . TG_EDIT, $type . ';command;edit;' . $id),
            self::button('🗑 ' . TG_DELETE, 'inlinekeyboard;delete;' . $type . ';' . $id),
        ];
    }

    /** @return list<InlineKeyboardButton> */
    public static function controlLine(string $params): array
    {
        return [
            self::button('⏫ ' . TG_COLLAPSE, 'inlinekeyboard;collapse;' . $params),
            self::button('❌ ' . TG_CLOSE, 'inlinekeyboard;close;'),
        ];
    }
}
