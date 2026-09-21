<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosTabs',
    template: 'components/experience/cos_tabs.html.twig',
)]
final class CosTabs
{
    public string $id = 'cos-tabs';
    public string $label = 'Sections';
    public string $active = '';

    /** @var list<array{key:string,label:string,disabled?:bool}> */
    public array $items = [];

    public function activeKey(): string
    {
        foreach ($this->items as $item) {
            if (
                $this->active !== ''
                && $item['key'] === $this->active
                && !($item['disabled'] ?? false)
            ) {
                return $this->active;
            }
        }

        foreach ($this->items as $item) {
            if (!($item['disabled'] ?? false)) {
                return $item['key'];
            }
        }

        return '';
    }
}
