<?php
declare(strict_types=1);

namespace Interfaces\Shared\Rendering;

use Interfaces\Shared\Rendering\Descriptor\ButtonDescriptor;
use Interfaces\Shared\Rendering\Descriptor\DescriptorInterface;
use Interfaces\Shared\Rendering\Descriptor\HeaderDescriptor;
use Interfaces\Shared\Rendering\Descriptor\ObjectBlockDescriptor;

class UIRenderService
{
    public function __construct(private ChannelRendererInterface $renderer)
    {
    }

    /** @param list<DescriptorInterface> $descriptors */
    public function render(array $descriptors): mixed
    {
        $elements = [];
        foreach ($descriptors as $descriptor) {
            $elements[] = match (true) {
                $descriptor instanceof HeaderDescriptor => $this->renderer->renderHeader($descriptor),
                $descriptor instanceof ObjectBlockDescriptor => $this->renderer->renderObjectBlock($descriptor),
                $descriptor instanceof ButtonDescriptor => $this->renderer->renderButton($descriptor),
            };
        }
        return $this->renderer->merge($elements);
    }
}
