<?php
declare(strict_types=1);

namespace Interfaces\Shared\Rendering;

use Interfaces\Shared\Rendering\Descriptor\ButtonDescriptor;
use Interfaces\Shared\Rendering\Descriptor\HeaderDescriptor;
use Interfaces\Shared\Rendering\Descriptor\ObjectBlockDescriptor;

interface ChannelRendererInterface
{
    public function renderHeader(HeaderDescriptor $descriptor): mixed;
    public function renderObjectBlock(ObjectBlockDescriptor $descriptor): mixed;
    public function renderButton(ButtonDescriptor $descriptor): mixed;
    public function merge(array $elements): mixed;
}
