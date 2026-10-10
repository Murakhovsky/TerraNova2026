<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Automation\Action;

use App\Persistence\Federation\FederationOutcomeOriginRecorder;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Domains\CapitalMarkets\Application\Contract\ResearchLabRepositoryInterface;
use Domains\CapitalMarkets\Application\Service\ResearchLabService;
use InvalidArgumentException;
use Kernel\Action\Action;
use Kernel\Action\Contract\IdempotentExternalActionHandlerInterface;
use Kernel\Action\ExecutionResult;
use Kernel\Action\ExternalActionIdempotency;
use Throwable;

/**
 * Federation-approved, single-attempt, recorded research result.
 *
 * An Action cannot mark an experiment as VALIDATED on arbitrary LLM text:
 * the experiment must already be COMPLETED and its immutable plan must
 * contain an explicit review decision plus non-empty source evidence.
 * VALIDATED means approved Research record, not demonstrated trading profit.
 */
final readonly class RecordValidatedResearchResultHandler implements IdempotentExternalActionHandlerInterface
{
    public const TYPE = 'capital_markets.research.result.record';

    public function __construct(
        private Connection $db,
        private ResearchLabRepositoryInterface $repository,
        private ResearchLabService $lab,
        private FederationOutcomeOriginRecorder $origins,
    ) {}

    public function supports(string $actionType): bool { return $actionType === self::TYPE; }

    public function idempotencyKey(Action $action): string { return ExternalActionIdempotency::resolve($action); }

    public function execute(Action $action): ExecutionResult
    {
        if ($action->targetType !== 'research_result' || !is_string($action->targetId)
            || $action->targetId === '') {
            return ExecutionResult::failure('Research outcome requires an immutable research_result target.');
        }

        try {
            return $this->db->transactional(function () use ($action): ExecutionResult {
                $org = $action->organizationId;
                $input = $action->parameters;
                $experimentId = $input['experiment_id'] ?? null;
                $review = $input['review_evidence'] ?? null;
                if (!is_string($experimentId) || $experimentId === ''
                    || !is_array($review) || ($review['decision'] ?? null) !== 'VALIDATED'
                    || !is_string($review['review_reference'] ?? null)
                    || trim($review['review_reference']) === ''
                    || !is_string($review['reviewed_by'] ?? null)
                    || trim($review['reviewed_by']) === ''
                    || !is_array($input['financial_metrics'] ?? null)
                    || !is_array($input['risk_metrics'] ?? null)
                    || !is_array($input['execution_metrics'] ?? null)
                    || !is_array($input['data_quality'] ?? null)
                    || !is_array($input['limitations'] ?? null)) {
                    throw new InvalidArgumentException('Validated Research requires human review evidence and structured experiment results.');
                }
                $reviewer = $this->db->fetchOne(
                    "SELECT decided_by_id FROM cos_approvals
                     WHERE organization_id=:org AND action_id=:action
                       AND status='APPROVED' AND decided_by_type='USER'",
                    ['org' => $org, 'action' => $action->id],
                );
                if (!is_string($reviewer) || $reviewer === ''
                    || $reviewer !== $review['reviewed_by'] || $reviewer === $action->sourceId) {
                    throw new InvalidArgumentException(
                        'Research validation must be confirmed by the independent Action approver.'
                    );
                }
                $experiment = $this->repository->getExperiment($org, $experimentId);
                if ($experiment === null || ($experiment['status'] ?? null) !== 'COMPLETED') {
                    throw new InvalidArgumentException('Only a completed Research experiment can have an approved validation result.');
                }
                if ($this->repository->getResultForExperiment($org, $experimentId) !== null) {
                    throw new InvalidArgumentException('Research result is already recorded; cannot relabel an earlier result.');
                }
                $record = [
                    'result_id' => $action->targetId,
                    'experiment_id' => $experimentId,
                    'status' => 'VALIDATED',
                    'financial_metrics' => $input['financial_metrics'],
                    'risk_metrics' => $input['risk_metrics'],
                    'execution_metrics' => $input['execution_metrics'],
                    'data_quality' => $input['data_quality'],
                    'limitations' => $input['limitations'],
                    'review_evidence' => $review,
                    'federation_action_id' => $action->id,
                    'created_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                        ->format('Y-m-d H:i:s.u'),
                ];
                $this->lab->recordResult($org, $record);
                // If receipt validation, ownership, or journal insertion fails,
                // rollback the native Research result as well.
                $this->origins->record($action, 'capital_markets', $action->targetId);
                return ExecutionResult::success(['result_id' => $action->targetId, 'status' => 'VALIDATED']);
            });
        } catch (Throwable $error) {
            return ExecutionResult::failure('Federation Research Action rejected: ' . $error->getMessage());
        }
    }
}
