<?php
declare(strict_types=1);

namespace App\Application\Sales\Command;

use Domains\Sales\Application\Contract\SalesWriteServiceFactoryInterface;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class ChangeSalesOpportunityStageCommandHandler implements CommandHandlerInterface
{
    public function __construct(private SalesWriteServiceFactoryInterface $writes)
    {
    }

    public function __invoke(ChangeSalesOpportunityStageCommand $command): SalesMutationResult
    {
        $result = $this->writes->forOrganization($command->organizationId->value())->changeOpportunityStage(
            $command->opportunityId,
            $command->targetStageId,
            $command->actorId,
            $command->correlationId,
            $command->lostReasonId,
            $command->lostReasonNote,
        );

        if (!$result->successful) {
            $reason = $result->reason ?? 'stage_change_failed';
            if ($reason === 'concurrent_stage_change') {
                return SalesMutationResult::failure(
                    'concurrent_stage_change',
                    'Opportunity stage changed concurrently. Reload and retry.',
                );
            }
            if (str_contains(strtolower($reason), 'deal was not found')) {
                return SalesMutationResult::failure('not_found', 'Opportunity not found.');
            }

            return SalesMutationResult::failure('invalid_stage_transition', $reason);
        }

        return SalesMutationResult::success('stage_changed', [
            'changed' => $result->changed,
            'previous_stage_id' => $result->previousStageId,
            'stage_id' => $result->stageId,
            'requires_approval' => $result->requiresApproval,
        ]);
    }
}
