<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Domains\Finance\Domain\Account;
use Domains\Finance\Domain\Invoice;
use Domains\Procurement\Domain\PurchaseRequest;
use Domains\Procurement\Domain\Supplier;
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

expectDomainSkeleton($case->organizationId->value() === 'org-1', 'Service Case must be tenant-scoped.');
expectDomainSkeleton($ticket->reference === 'SUP-1', 'Ticket vocabulary must autoload.');
expectDomainSkeleton($account->currency === 'USD', 'Finance Account must expose currency.');
expectDomainSkeleton($invoice->total->minorUnits() === 12500, 'Finance must use Money for monetary amounts.');
expectDomainSkeleton($supplier->name === 'ACME Supplies' && $request->id === 'pr-1', 'Procurement vocabulary must autoload.');

foreach (['Service', 'Finance', 'Procurement'] as $name) {
    $definition = require dirname(__DIR__, 2) . '/app/Domains/' . $name . '/module.php';
    $module = ModuleDefinition::fromArray($definition);
    expectDomainSkeleton($module->contributions->runtimeModuleService === null, $name . ' must remain skeleton-only without runtime service.');
    expectDomainSkeleton($module->contributions->migrationFiles === [], $name . ' must not declare persistence migrations yet.');
    expectDomainSkeleton($module->manifest->enabledByDefault === false, $name . ' skeleton must not auto-enable runtime behavior.');
}

echo "Service, Finance and Procurement skeleton contracts passed.\n";
