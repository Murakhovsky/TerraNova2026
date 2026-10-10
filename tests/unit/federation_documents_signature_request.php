<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Domains\Documents\Automation\Action\RequestSignatureHandler;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleDiscovery;
use Platform\Orchestration\Goal\CapabilityJsonInputValidator;

$root = dirname(__DIR__, 2);
$catalog = new CanonicalCapabilityCatalog(new ModuleCatalog(
    (new ModuleDiscovery($root . '/app/Domains'))->discover(),
));
$contracts = $catalog->executables();
$contract = $contracts[RequestSignatureHandler::TYPE] ?? null;
if ($contract === null || $contract->ownerDomain !== 'documents'
    || $contract->executionBinding !== 'action:documents.signature.request'
    || $contract->approvalPolicy !== 'required'
    || $contract->retryPolicy['max_attempts'] !== 1) {
    throw new RuntimeException('Documents request capability is not independently approved, typed and owned.');
}
$validator = new CapabilityJsonInputValidator();
$valid = [
    'target_type' => 'document',
    'target_id' => 'DOC-01',
    'parameters' => ['signer_id' => 'signer-01'],
];
$validator->validate($contract, $valid);
foreach ([
    ['target_type' => 'document', 'target_id' => 'DOC-01', 'parameters' => []],
    ['target_type' => 'document_signature', 'target_id' => 'DOC-01', 'parameters' => ['signer_id' => 's']],
    ['target_type' => 'document', 'target_id' => '', 'parameters' => ['signer_id' => 's']],
    ['target_type' => 'document', 'target_id' => 'DOC-01',
        'parameters' => ['signer_id' => 's', 'signature_reference' => 'FAKE-SIGNED']],
] as $input) {
    try {
        $validator->validate($contract, $input);
        throw new RuntimeException('Forgery or implicit signature input was accepted.');
    } catch (DomainException) {
        // Missing signer, incorrect target and forged signed-status
        // parameters must fail before the Action is scheduled.
    }
}
// The handler must refuse incomplete actions even without accessing a
// configured Documents runtime service.
$handler = (new ReflectionClass(RequestSignatureHandler::class))->newInstanceWithoutConstructor();
$invalid = new Action(
    bin2hex(random_bytes(16)), 'test-org', RequestSignatureHandler::TYPE,
    'document_signature', 'SIG-01', ['signer_id'=>'s'], 'USER', '1234',
    'APPROVAL_REQUIRED', 'HIGH', 'fed:key', new DateTimeImmutable(),
    ActionStatus::Running,
);
if ($handler->execute($invalid)->successful) {
    throw new RuntimeException('Documents request Action was allowed to count as a signed document.');
}
echo "Federation Documents request: strict capability schema, no signature forgery, preflight passed.\n";
