<?php
declare(strict_types=1);

namespace Domains\Documents\Automation\Action;

use Domains\Growth\Application\Contract\GrowthHandoffBoundary;
use Domains\Sales\Application\Contract\SalesWorkspaceReadModelInterface;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Action\Contract\IdempotentExternalActionHandlerInterface;
use Kernel\Action\ExecutionResult;
use Kernel\Action\ExternalActionIdempotency;
use Kernel\Module\ActiveModuleResolver;
use Platform\Documents\Contract\DocumentAttachmentPort;
use Platform\Documents\Contract\DocumentTemplateGenerationPort;
use Throwable;

/**
 * Prepare a native, unsent proposal from a tenant-owned template.
 * The Sales Lead must be the exact native result of a Growth accepted handoff.
 * No signature, sending or contract acceptance is performed.
 */
final readonly class PrepareProposalDraftHandler implements IdempotentExternalActionHandlerInterface
{
    public const TYPE = 'documents.proposal.prepare';

    public function __construct(
        private DocumentTemplateGenerationPort $generator,
        private DocumentAttachmentPort $attachments,
        private GrowthHandoffBoundary $handoffs,
        private SalesWorkspaceReadModelInterface $sales,
        private ActiveModuleResolver $modules,
    ) {}

    public function supports(string $actionType): bool { return $actionType === self::TYPE; }

    public function idempotencyKey(Action $action): string { return ExternalActionIdempotency::resolve($action); }

    public function execute(Action $action): ExecutionResult
    {
        $candidateId = $action->targetId;
        $templateId = $action->parameters['template_id'] ?? null;
        $variables = $action->parameters['variables'] ?? [];
        $title = $action->parameters['title'] ?? null;

        if ($action->status !== ActionStatus::Running || $action->sourceType !== 'USER'
            || !ctype_digit($action->sourceId) || (int)$action->sourceId < 1
            || $action->targetType !== 'growth_candidate'
            || !is_string($candidateId) || trim($candidateId) === ''
            || !is_string($templateId) || trim($templateId) === ''
            || !is_array($variables) || ($variables !== [] && array_is_list($variables))
            || ($title !== null && (!is_string($title) || trim($title) === ''))) {
            return ExecutionResult::failure('Proposal requires a running approved user Action, native Sales Lead, Growth Candidate and template.');
        }
        foreach ($variables as $key => $value) {
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,79}$/', $key)
                || !is_string($value) || mb_strlen($value) > 4000) {
                return ExecutionResult::failure('Proposal template variables must be bounded scalar strings.');
            }
        }
        try {
            $org = $action->organizationId;
            if (!$this->modules->isEnabled($org,'growth') || !$this->modules->isEnabled($org,'sales')) {
                return ExecutionResult::failure('Growth and Sales must both be enabled for proposal preparation.');
            }
            $handoff = $this->handoffs->handoffBrief($org,$candidateId);
            $candidate = $handoff['candidate'] ?? null;
            $attempt = $handoff['latest_attempt'] ?? null;
            if (!is_array($candidate) || !is_array($attempt)
                || ($candidate['status'] ?? null) !== 'handed_off'
                || ($attempt['status'] ?? null) !== 'accepted'
                || ($attempt['target_domain'] ?? null) !== 'sales'
                || ($attempt['target_reference_type'] ?? null) !== 'sales_lead'
                || !is_string($attempt['target_reference_id'] ?? null)
                || !ctype_digit($attempt['target_reference_id']) || (int)$attempt['target_reference_id'] < 1) {
                return ExecutionResult::failure('Sales Lead is not the accepted, tenant-owned Growth handoff target.');
            }
            $leadId = $attempt['target_reference_id'];
            $lead = $this->sales->lead($org,(int)$leadId);
            if (!is_array($lead) || ($lead['source'] ?? null) !== 'growth-handoff'
                || (string)($lead['id'] ?? '') !== $leadId) {
                return ExecutionResult::failure('Canonical Sales Lead is missing or not created by Growth handoff.');
            }
            $actor = (int)$action->sourceId;
            $correlation = $action->correlationId !== '' ? $action->correlationId : $action->id;
            $key = 'fed-proposal-' . substr(hash('sha256',$this->idempotencyKey($action)),0,40);
            $document = $this->generator->generateFromTemplate(
                $org,$actor,$correlation,$templateId,$key . '-generate',
                ['title'=>$title ?? 'Commercial proposal draft', 'variables'=>$variables],
            );
            $documentId = $document['document_id'] ?? null;
            if (!is_string($documentId) || $documentId === '') {
                return ExecutionResult::failure('Canonical Documents generation did not return a persisted document identifier.');
            }
            $relation = $this->attachments->attachExistingDocument(
                $org,$actor,$correlation,$documentId,'sales.lead',$leadId,$key . '-attach',
            );
            if (!is_string($relation['relation_id'] ?? null) || $relation['relation_id'] === ''
                || ($relation['document_id'] ?? null) !== $documentId
                || ($relation['related_type'] ?? null) !== 'sales.lead'
                || (string)($relation['related_id'] ?? '') !== $leadId) {
                return ExecutionResult::failure('Proposal exists but is not reliably attached to the approved Sales Lead; reconcile manually.');
            }
            return ExecutionResult::success([
                'document_id'=>$documentId,
                'relation_id'=>$relation['relation_id'],
                'sales_lead_id'=>$leadId,
                'candidate_id'=>$candidateId,
                'status'=>'prepared_not_sent',
                'goal_outcome_verified'=>false,
            ]);
        } catch (Throwable $error) {
            return ExecutionResult::failure('Proposal draft requires manual reconciliation: '.$error->getMessage());
        }
    }
}
