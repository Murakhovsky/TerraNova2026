<?php
declare(strict_types=1);

namespace Interfaces\Telegram\Rendering;

use Interfaces\Shared\Rendering\ChannelRendererInterface;
use Interfaces\Shared\Rendering\Descriptor\ButtonDescriptor;
use Interfaces\Shared\Rendering\Descriptor\HeaderDescriptor;
use Interfaces\Shared\Rendering\Descriptor\ObjectBlockDescriptor;

class TelegramRenderer implements ChannelRendererInterface
{
    private array $keyboard = [];

    public function renderHeader(HeaderDescriptor $descriptor): array
    {
        return ['text' => "*{$descriptor->text}*\n{$descriptor->subtext}", 'parse_mode' => 'Markdown'];
    }

    public function renderObjectBlock(ObjectBlockDescriptor $descriptor): array
    {
        $text = "*{$descriptor->title}*\n";
        foreach ($descriptor->fields as $key => $value) $text .= "\n{$key}: {$value}";
        $payload = ['text' => $text, 'parse_mode' => 'Markdown'];
        if ($descriptor->image) $payload['photo'] = $descriptor->image;
        return $payload;
    }

    public function renderButton(ButtonDescriptor $descriptor): array
    {
        $this->keyboard[] = [['text' => $descriptor->text, 'callback_data' => $descriptor->payload]];
        return [];
    }

    public function merge(array $elements): array
    {
        $messages = array_values(array_filter($elements, static fn(array $element): bool => isset($element['text'])));
        $last = array_pop($messages) ?: [];
        if ($this->keyboard) $last['reply_markup'] = ['inline_keyboard' => $this->keyboard];
        return $last;
    }
}
