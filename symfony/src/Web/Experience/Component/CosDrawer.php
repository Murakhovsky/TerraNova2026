<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosDrawer',
    template: 'components/experience/cos_drawer.html.twig',
)]
final class CosDrawer
{
    public string $id = 'cos-drawer';
    public string $title = '';
    public string $triggerLabel = '';
    public string $side = 'end';
    public string $closeLabel = 'Close';
    public bool $dismissible = true;

    public function cssClass(): string
    {
        $side = in_array($this->side, ['start', 'end'], true) ? $this->side : 'end';

        return 'cos-dialog cos-drawer cos-drawer--' . $side;
    }
}
