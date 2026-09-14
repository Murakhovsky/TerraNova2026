<?php
declare(strict_types=1);

namespace Interfaces\Shared\Rendering\Descriptor;

class ButtonDescriptor implements DescriptorInterface
{
    public function __construct(public string $text, public string $payload)
    {
    }
}
