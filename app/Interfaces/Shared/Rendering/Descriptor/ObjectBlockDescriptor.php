<?php
declare(strict_types=1);

namespace Interfaces\Shared\Rendering\Descriptor;

class ObjectBlockDescriptor implements DescriptorInterface
{
    public function __construct(public string $title, public array $fields, public ?string $image = null)
    {
    }
}
