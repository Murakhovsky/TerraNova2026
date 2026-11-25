<?php
declare(strict_types=1);

use Domains\Sales\Automation\Action\SalesGovernedBusinessOperations;
use Domains\Sales\Automation\Action\SalesGovernedOrderHandler;
use Domains\Sales\Automation\Policy\SalesPolicyCatalog;
use Kernel\Action\Action;
use Kernel\Module\CapabilityContract;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$require = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$deny = static function (callable $operation): void {
    try { $operation(); } catch (DomainException) { return; }
    throw new RuntimeException('Mismatched target was accepted.');
};
$manifest = require $root.'/app/Domains/Sales/module.php';
$contracts = [];
foreach ($manifest['contributions']['capability_contracts'] as $raw) {
    $contract = CapabilityContract::fromArray($raw);
    $contracts[$contract->id] = $contract;
}
$types = SalesGovernedBusinessOperations::types();
$require(count($types) === 28 && count(array_unique($types)) === 28, 'R1 coverage changed.');
$require(array_intersect($types, SalesGovernedOrderHandler::types()) === [], 'Order bindings duplicated.');
$policies = (new SalesPolicyCatalog())->policies('r1-test');
$mapped = [];
foreach ($types as $id) {
    $nativeId = SalesGovernedBusinessOperations::nativeId($id);
    $mapped[] = $nativeId;
    $native = $contracts[$nativeId];
    $governed = $contracts[$id];
    $require($native->executionBinding === 'command:'.$nativeId && $native->approvalPolicy === 'none'
        && $governed->executionBinding === 'action:'.$id && $governed->approvalPolicy === 'required'
        && $governed->sideEffectLevel === 'internal' && $governed->idempotency === 'required'
        && $governed->async && $governed->retryPolicy['max_attempts'] === 1
        && $native->outputSchema === $governed->outputSchema
        && in_array($id, $manifest['contributions']['capabilities'], true), 'Unsafe contract: '.$id);
    $schema = json_decode((string) file_get_contents($root.'/'.$governed->inputSchema), true, 64, JSON_THROW_ON_ERROR);
    $nativeSchema = json_decode((string) file_get_contents($root.'/'.$native->inputSchema), true, 64, JSON_THROW_ON_ERROR);
    unset($nativeSchema['$schema']);
    $require($schema['properties']['parameters'] === $nativeSchema
        && $schema['additionalProperties'] === false
        && $schema['required'] === ['target_type','target_id','parameters'], 'Native schema drift: '.$id);
}
foreach ($contracts as $id => $c) {
    if ($c->ownerDomain === 'sales' && $c->executionBinding === 'command:'.$id
        && $id !== 'sales.order.create' && !str_starts_with($id, 'sales.order.lifecycle.')) {
        $require(in_array($id, $mapped, true), 'Unmapped native command '.$id);
    }
}
$action = static fn (string $type, string $targetType, string $targetId, array $params): Action => new Action(
    str_repeat('a',32), 'r1-test', $type, $targetType, $targetId, $params, 'USER','10',
    'APPROVAL_REQUIRED','HIGH','wf:'.str_repeat('b',64),new DateTimeImmutable(),correlationId:'r1');
SalesGovernedBusinessOperations::assertTarget($action('sales.governed.lead.qualify','lead','15',['lead_id'=>15]));
$deny(fn () => SalesGovernedBusinessOperations::assertTarget($action('sales.governed.lead.qualify','lead','16',['lead_id'=>15])));
$deny(fn () => SalesGovernedBusinessOperations::assertTarget($action('sales.governed.lead.qualify','deal','15',['lead_id'=>15])));
$deny(fn () => SalesGovernedBusinessOperations::assertTarget($action('sales.governed.offer.revise','offer',str_repeat('c',32),['offer_id'=>str_repeat('d',32)])));
$deny(fn () => SalesGovernedBusinessOperations::assertTarget($action('sales.governed.order.source.sync.start','sales_order_connection',str_repeat('c',32),['connection_id'=>str_repeat('d',32)])));
$deny(fn () => SalesGovernedBusinessOperations::assertTarget($action('sales.governed.lead.create','sales_intent','15',[])));
$deny(fn () => SalesGovernedBusinessOperations::assertTarget($action('sales.governed.unknown','lead','15',['lead_id'=>15])));
echo "Sales R1 governed capability contracts, native schema parity and target fencing PASS.\n";
