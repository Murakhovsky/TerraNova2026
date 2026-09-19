<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Domains\Construction\Domain\ConstructionObject;
use Domains\Construction\Domain\Project;
use Domains\Finance\Domain\Account;
use Domains\Finance\Domain\Invoice;
use Domains\HR\Domain\Employee;
use Domains\HR\Domain\Recruitment;
use Domains\Procurement\Domain\PurchaseRequest;
use Domains\Procurement\Domain\Supplier;
use Domains\RealEstate\Domain\BrokerageCase;
use Domains\RealEstate\Domain\Mandate;
use Domains\Service\Domain\ServiceCase;
use Domains\Service\Domain\Ticket;
use Kernel\Module\ModuleDefinition;
use Kernel\Shared\Domain\Money;
use Kernel\Shared\Domain\OrganizationId;

function expectDomainSkeleton(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$org = OrganizationId::fromString('org-1');
$case = new ServiceCase('case-1', $org, 'Customer onboarding issue');
$ticket = new Ticket('ticket-1', $org, 'request-1', 'SUP-1');
$account = new Account('account-1', $org, 'Operating account', 'USD');
$invoice = new Invoice('invoice-1', $org, 'INV-1', new Money(12500, 'USD'));
$supplier = new Supplier('supplier-1', $org, 'ACME Supplies');
$request = new PurchaseRequest('pr-1', $org, 'Purchase equipment');
$employee = new Employee('employee-1', $org, 'Ada Lovelace', 'position-1');
$recruitment = new Recruitment('recruitment-1', $org, 'candidate-1', 'position-1');
$project = new Project('project-1', $org, 'West site');
$object = new ConstructionObject('object-1', $org, 'project-1', 'Building A');
$brokerageCase = new BrokerageCase('re-1', $org, 'property-1', 'Seller mandate');
$mandate = new Mandate('mandate-1', $org, 'property-1', 'party-1');

expectDomainSkeleton($case->organizationId->value() === 'org-1', 'Service Case must be tenant-scoped.');
expectDomainSkeleton($ticket->reference === 'SUP-1', 'Ticket vocabulary must autoload.');
expectDomainSkeleton($account->currency === 'USD', 'Finance Account must expose currency.');
expectDomainSkeleton($invoice->total->minorUnits() === 12500, 'Finance must use Money for monetary amounts.');
expectDomainSkeleton($supplier->name === 'ACME Supplies' && $request->id === 'pr-1', 'Procurement vocabulary must autoload.');
expectDomainSkeleton($employee->positionId === 'position-1' && $recruitment->candidateId === 'candidate-1', 'HR vocabulary must autoload.');
expectDomainSkeleton($project->name === 'West site' && $object->projectId === 'project-1', 'Construction vocabulary must autoload.');
expectDomainSkeleton($brokerageCase->propertyId === 'property-1' && $mandate->partyId === 'party-1', 'RealEstate vocabulary must reference Property without owning it.');

foreach (['Finance', 'Procurement', 'HR', 'Construction'] as $name) {
    $definition = require dirname(__DIR__, 2) . '/app/Domains/' . $name . '/module.php';
    $module = ModuleDefinition::fromArray($definition);
    expectDomainSkeleton($module->contributions->runtimeModuleService === null, $name . ' must remain skeleton-only without runtime service.');
    expectDomainSkeleton($module->contributions->migrationFiles === [], $name . ' must not declare persistence migrations yet.');
    expectDomainSkeleton($module->manifest->enabledByDefault === false, $name . ' skeleton must not auto-enable runtime behavior.');
}
$serviceDefinition = ModuleDefinition::fromArray(require dirname(__DIR__, 2) . '/app/Domains/Service/module.php');
expectDomainSkeleton($serviceDefinition->contributions->runtimeModuleService === 'serviceDomainModule', 'Service V0.2 must expose its runtime module.');
expectDomainSkeleton(
    in_array('app/migrations/20260919_000064_service_wave11_cutover.sql', $serviceDefinition->contributions->migrationFiles, true),
    'Service V0.2 must declare its persistence migration.',
);
expectDomainSkeleton($serviceDefinition->manifest->enabledByDefault === true, 'Service V0.2 business cutover must be enabled by default.');

$realEstateDefinition = ModuleDefinition::fromArray(require dirname(__DIR__, 2) . '/app/Domains/RealEstate/module.php');
expectDomainSkeleton(in_array('property', $realEstateDefinition->manifest->dependencies, true), 'RealEstate must declare its Property dependency.');
expectDomainSkeleton(in_array('sales', $realEstateDefinition->manifest->dependencies, true), 'RealEstate must declare its Sales dependency.');
expectDomainSkeleton($realEstateDefinition->contributions->runtimeModuleService === 'realEstateDomainModule', 'RealEstate V0.2 must expose its runtime module.');
expectDomainSkeleton(
    in_array('app/migrations/20260918_000062_real_estate_wave9_cutover.sql', $realEstateDefinition->contributions->migrationFiles, true),
    'RealEstate V0.2 must declare its brokerage persistence migration.',
);
expectDomainSkeleton($realEstateDefinition->manifest->enabledByDefault === true, 'RealEstate V0.2 business cutover must be enabled by default.');

echo "V1 Domain skeleton contracts passed.\n";
