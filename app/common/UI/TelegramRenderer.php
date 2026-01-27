<?php
namespace Common\UI\Renderer;

use Common\UI\Descriptor\{
    HeaderDescriptor,
    ObjectBlockDescriptor,
    ButtonDescriptor
};

class TelegramRenderer implements ChannelRendererInterface
{
    private array $keyboard = [];

    public function renderHeader(HeaderDescriptor $d): array
    {
        return ['text'=>"*{$d->text}*\n{$d->subtext}", 'parse_mode'=>'Markdown'];
    }

    public function renderObjectBlock(ObjectBlockDescriptor $d): array
    {
        $text  = "*{$d->title}*\n";
        foreach ($d->fields as $k=>$v) {
            $text .= "\n{$k}: {$v}";
        }
        $payload = ['text'=>$text,'parse_mode'=>'Markdown'];
        if ($d->image) {
            $payload['photo'] = $d->image;
        }
        return $payload;
    }

    public function renderButton(ButtonDescriptor $d): array
    {
        // inline-кнопка
        $this->keyboard[] = [['text'=>$d->text,'callback_data'=>$d->payload]];
        return [];
    }

    public function merge(array $elements): array
    {
        // збираємо всі текстові частини й клавіатуру
        $messages = array_filter($elements, fn($e)=> isset($e['text']));
        $last     = array_pop($messages) ?: [];
        if ($this->keyboard) {
            $last['reply_markup'] = ['inline_keyboard'=>$this->keyboard];
        }
        return $last;
    }
}
