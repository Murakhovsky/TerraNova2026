<?php
declare(strict_types=1);

namespace Interfaces\Web\Rendering;

use Interfaces\Shared\Rendering\ChannelRendererInterface;
use Interfaces\Shared\Rendering\Descriptor\ButtonDescriptor;
use Interfaces\Shared\Rendering\Descriptor\HeaderDescriptor;
use Interfaces\Shared\Rendering\Descriptor\ObjectBlockDescriptor;

class WebRenderer implements ChannelRendererInterface
{
    public function renderHeader(HeaderDescriptor $descriptor): string
    {
        return '<h2>' . $this->escape($descriptor->text) . '</h2>'
            . ($descriptor->subtext ? '<p class="sub">' . $this->escape($descriptor->subtext) . '</p>' : '');
    }

    public function renderObjectBlock(ObjectBlockDescriptor $descriptor): string
    {
        $html = '<div class="object-block"><h3>' . $this->escape($descriptor->title) . '</h3><ul>';
        foreach ($descriptor->fields as $key => $value) {
            $html .= '<li><strong>' . $this->escape((string) $key) . ':</strong> ' . $this->escape((string) $value) . '</li>';
        }
        $html .= '</ul>';
        if ($descriptor->image) $html .= '<img src="' . $this->escape($descriptor->image) . '" alt="">';
        return $html . '</div>';
    }

    public function renderButton(ButtonDescriptor $descriptor): string
    {
        return '<button type="button" data-action="' . $this->escape($descriptor->payload) . '">'
            . $this->escape($descriptor->text) . '</button>';
    }

    public function merge(array $elements): string
    {
        return implode("\n", $elements);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
