<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosDecisionTrace',
    template: 'components/experience/cos_decision_trace.html.twig',
)]
final class CosDecisionTrace
{
    /** @var list<array<string,mixed>> */
    public array $steps = [];
    public string $label = 'Decision Trace';
    public string $emptyCopy = 'No traceable downstream decision exists yet.';
}
