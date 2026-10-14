<?php
declare(strict_types=1);

namespace App\Persistence\Workflow;

use DomainException;
use Kernel\Module\ActiveModuleResolver;
use Platform\Workflow\Contract\WorkflowNonActionEvidenceAdapterInterface;
use Platform\Workflow\Contract\WorkflowNonActionReceiptEvidenceInterface;
use Platform\Workflow\Model\WorkflowRunIdentity;

/**
 * Explicit opt-in registry of domain-owned evidence. No generated SQL or
 * arbitrary caller-chosen table, module or verifier. Unknown kinds fail closed.
 */
final readonly class WorkflowNonActionReceiptRegistry implements WorkflowNonActionReceiptEvidenceInterface
{
    /** @param iterable<WorkflowNonActionEvidenceAdapterInterface> $adapters */
    public function __construct(
        private ActiveModuleResolver $modules,
        private iterable $adapters,
    ) {}

    public function assertVerified(
        WorkflowRunIdentity $identity,
        string $stepId,
        string $reference,
        string $inputDigest,
        array $pinnedStep,
    ): void {
        $evidence=$pinnedStep['evidence']??null;
        $kind=is_array($evidence)?($evidence['kind']??null):null;
        if (($pinnedStep['id']??null)!==$stepId
            || ($pinnedStep['side_effect_level']??null)!=='none'
            || !is_array($evidence) || !is_string($kind) || $kind===''
            || ($pinnedStep['execution_binding']??null)!=='read:'.$kind
            || !preg_match('/^[a-f0-9]{64}$/',$inputDigest)
            || ($evidence['input_digest']??null)!==$inputDigest
            || ($evidence['run_id']??null)!==$identity->runId
            || !is_string($reference) || $reference==='') {
            throw new DomainException('Pinned non-Action evidence identity/binding/input is invalid.');
        }
        $owner=null;
        foreach($this->adapters as $adapter) {
            if(!$adapter instanceof WorkflowNonActionEvidenceAdapterInterface) {
                throw new DomainException('Untrusted Workflow evidence adapter registered.');
            }
            if($adapter->kind()!==$kind) continue;
            if($owner!==null) {
                throw new DomainException('Ambiguous Workflow evidence kind registration.');
            }
            $owner=$adapter;
        }
        if($owner===null || ($pinnedStep['owner_domain']??null)!==$owner->ownerDomain()
            || ($owner->ownerDomain()!=='platform.documents'
                && !$this->modules->isEnabled($identity->organizationId,$owner->ownerDomain()))) {
            throw new DomainException('Missing or disabled Domain-owned evidence verifier.');
        }
        $owner->assertVerified($identity,$stepId,$reference,$evidence);
    }
}
