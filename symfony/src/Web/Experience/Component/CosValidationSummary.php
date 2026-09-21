<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosValidationSummary',
    template: 'components/experience/cos_validation_summary.html.twig',
)]
final class CosValidationSummary
{
    public string $id = 'cos-validation-summary';
    public string $title = 'Check the highlighted fields.';

    /** @var list<string> */
    public array $errors = [];
}
