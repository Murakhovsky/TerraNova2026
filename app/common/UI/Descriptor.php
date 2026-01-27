<?php
namespace Common\UI\Descriptor;

interface DescriptorInterface {}

class HeaderDescriptor implements DescriptorInterface
{
    public function __construct(
        public string $text,
        public ?string $subtext = null
    ){}
}

class ObjectBlockDescriptor implements DescriptorInterface
{
    public function __construct(
        public string $title,
        public array  $fields,   // ['Площа'=>'50м²', 'Ціна'=>'$30 000']
        public ?string $image = null
    ){}
}

class ButtonDescriptor implements DescriptorInterface
{
    public function __construct(
        public string $text,
        public string $payload
    ){}
}
