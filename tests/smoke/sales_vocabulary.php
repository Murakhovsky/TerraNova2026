<?php
declare(strict_types=1);

use Domains\Sales\Application\Support\ClientCaseInput;
use Domains\Sales\Model\ClientCaseStatus;
use Domains\Sales\Model\ClientCaseType;
use Domains\Sales\Model\DealChangeSet;
use Domains\Sales\Model\LeadStatus;
use Domains\Sales\Model\PipelineStage;
use Domains\Sales\Model\PropertyMatchStatus;
use Domains\Sales\Model\SalesActivityType;
use Domains\Sales\Model\SalesCurrency;
use Domains\Sales\Model\SalesPriority;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

foreach ([PipelineStage::class, ClientCaseStatus::class, ClientCaseType::class, SalesPriority::class,
             LeadStatus::class, PropertyMatchStatus::class, SalesActivityType::class, SalesCurrency::class] as $enum) {
    $values = $enum::values();
    if ($values === [] || count($values) !== count(array_unique($values))) {
        throw new RuntimeException('Sales enum is empty or contains duplicate values: ' . $enum);
    }
    foreach ($values as $value) {
        if (!$enum::accepts($value)) throw new RuntimeException('Sales enum rejected its own value: ' . $enum);
    }
}

$changes = DealChangeSet::fromArray(['stage' => PipelineStage::Negotiation->value, 'priority' => SalesPriority::High->value]);
if ($changes->toArray() !== ['stage' => 'negotiation', 'priority' => 'high']) {
    throw new RuntimeException('DealChangeSet does not use the canonical Sales vocabulary.');
}

try {
    DealChangeSet::fromArray(['stage' => 'provider_specific_stage']);
    throw new RuntimeException('DealChangeSet accepted an external provider stage.');
} catch (InvalidArgumentException) {
}

$case = ClientCaseInput::caseData([], 'Test client', null, null, null);
if ($case['stage'] !== PipelineStage::New->value
    || $case['status'] !== ClientCaseStatus::Active->value
    || $case['type'] !== ClientCaseType::Buy->value
) {
    throw new RuntimeException('ClientCase defaults diverged from the canonical Sales vocabulary.');
}

echo "Sales vocabulary passed: typed states are canonical across Deal and ClientCase flows.\n";

