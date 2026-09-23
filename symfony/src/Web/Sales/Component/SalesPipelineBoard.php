<?php

declare(strict_types=1);

namespace App\Web\Sales\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'SalesPipelineBoard', template: 'components/sales/sales_pipeline_board.html.twig')]
final class SalesPipelineBoard
{
    /** @var list<array<string,mixed>> */
    public array $stages = [];

    /** @var list<array{id:string,label:string}> */
    public array $stageOptions = [];
}
