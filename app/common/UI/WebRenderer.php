<?php

namespace Common\UI\Renderer;

use Common\UI\Descriptor\{
    HeaderDescriptor,
    ObjectBlockDescriptor,
    ButtonDescriptor
};

class WebRenderer implements ChannelRendererInterface
{
    public function renderHeader(HeaderDescriptor $d): string
    {
        return "<h2>{$d->text}</h2>"
            . ($d->subtext ? "<p class='sub'>{$d->subtext}</p>" : '');
    }

    public function renderObjectBlock(ObjectBlockDescriptor $d): string
    {
        $html = "<div class='object-block'><h3>{$d->title}</h3><ul>";
        foreach ($d->fields as $k=>$v) {
            $html .= "<li><strong>{$k}:</strong> {$v}</li>";
        }
        $html .= "</ul>";
        if ($d->image) {
            $html .= "<img src='{$d->image}'/>";
        }
        $html .= "</div>";
        return $html;
    }

    public function renderButton(ButtonDescriptor $d): string
    {
        return "<button onclick=\"action('{$d->payload}')\">{$d->text}</button>";
    }

    public function merge(array $elements): string
    {
        return implode("\n", $elements);
    }
}
