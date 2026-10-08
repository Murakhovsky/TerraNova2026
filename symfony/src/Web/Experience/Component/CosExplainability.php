<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosExplainability',
    template: 'components/experience/cos_explainability.html.twig',
)]
final class CosExplainability
{
    public string $title = 'Why?';
    public string $what = '';
    public string $why = '';
    /** @var list<string> */
    public array $evidence = [];
    public string $recommendedAction = '';
}
