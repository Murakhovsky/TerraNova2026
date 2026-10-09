<?php
declare(strict_types=1);

namespace Domains\Documents\Bootstrap;

use Domains\Documents\Automation\Action\RequestSignatureHandler;
use Domains\Documents\Automation\Action\PrepareProposalDraftHandler;
use Domains\Documents\Automation\Action\RecordVerifiedSignatureHandler;
use Kernel\Module\Contract\ActionOwningModuleInterface;
use Kernel\Module\Contract\BootstrapPolicyProvidingModuleInterface;
use Kernel\Module\Contract\PolicyProvidingModuleInterface;
use Kernel\Module\DomainModuleInterface;
use Kernel\Policy\ActionPolicy;
use Kernel\Policy\PolicyDecision;

/** Canonical owning adapter for the shared Platform Documents bounded context. */
final readonly class DocumentsDomainModule implements
    DomainModuleInterface,
    ActionOwningModuleInterface,
    PolicyProvidingModuleInterface,
    BootstrapPolicyProvidingModuleInterface
{
    public function __construct(private RequestSignatureHandler $requestSignature,private RecordVerifiedSignatureHandler $recordVerifiedSignature,
        private PrepareProposalDraftHandler $prepareProposal) {}

    public function name(): string { return 'documents'; }
    public function actionTypes(): array { return [RequestSignatureHandler::TYPE,RecordVerifiedSignatureHandler::TYPE,PrepareProposalDraftHandler::TYPE]; }
    public function actionHandlers(): array { return [$this->requestSignature,$this->recordVerifiedSignature,$this->prepareProposal]; }

    /** @return list<ActionPolicy> */
    public function policies(string $organizationId): array
    {
        $id = $organizationId === 'default' ? 'documents-signature-request-review-v1'
            : substr(hash('sha256', $organizationId . ':documents-signature-request-review-v1'), 0, 32);
        return [
            new ActionPolicy($id,$organizationId,RequestSignatureHandler::TYPE,[],
                PolicyDecision::ApprovalRequired,10,'Document signature requests require independent human approval'),
            new ActionPolicy(
                $organizationId === 'default' ? 'documents-signature-provider-verified-v1'
                    : substr(hash('sha256',$organizationId.':documents-signature-provider-verified-v1'),0,32),
                $organizationId,RecordVerifiedSignatureHandler::TYPE,[],
                PolicyDecision::ApprovalRequired,10,'Record provider-verified human signature only after human approval'),
            new ActionPolicy(
                $organizationId === 'default' ? 'documents-growth-sales-proposal-draft-v1'
                    : substr(hash('sha256',$organizationId.':documents-growth-sales-proposal-draft-v1'),0,32),
                $organizationId,PrepareProposalDraftHandler::TYPE,[],
                PolicyDecision::ApprovalRequired,10,'Generate approved sales proposal draft from tenant template'),
        ];
    }

    /** @return list<ActionPolicy> */
    public function bootstrapPolicies(): array { return $this->policies('default'); }
}
