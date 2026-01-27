<?php
namespace Common\UI;

use Common\UI\Descriptor\DescriptorInterface;
use Common\UI\Renderer\ChannelRendererInterface;

class UIRenderService
{
    public function __construct(
        private ChannelRendererInterface $renderer
    ){}

    /**
     * @param DescriptorInterface[] $descriptors
     */
    public function render(array $descriptors): mixed
    {
        $elements = [];
        foreach ($descriptors as $d) {
            match (true) {
                $d instanceof \Common\UI\Descriptor\HeaderDescriptor
                => $elements[] = $this->renderer->renderHeader($d),
                $d instanceof \Common\UI\Descriptor\ObjectBlockDescriptor
                => $elements[] = $this->renderer->renderObjectBlock($d),
                $d instanceof \Common\UI\Descriptor\ButtonDescriptor
                => $elements[] = $this->renderer->renderButton($d),
            };
        }
        return $this->renderer->merge($elements);
    }
}
