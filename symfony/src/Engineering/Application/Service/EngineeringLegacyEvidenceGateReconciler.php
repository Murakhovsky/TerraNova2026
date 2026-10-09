<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

use App\Engineering\Application\Lock\EngineeringWorkflowLockInterface;
use App\Engineering\Application\Observability\EngineeringExecutionJournal;
use App\Engineering\Application\Persistence\EngineeringFeatureStoreInterface;
use App\Engineering\Application\Persistence\EngineeringHumanDecisionStoreInterface;
use App\Engineering\Application\Persistence\EngineeringWorkflowStoreInterface;
use App\Engineering\Application\Repository\EngineeringRepositoryGatewayInterface;
use App\Engineering\Application\Workflow\EngineeringWorkflowCoordinator;
use App\Engineering\Domain\Agent\AgentRole;
use App\Engineering\Domain\Workflow\EngineeringWorkflowState;
use RuntimeException;

final readonly class EngineeringLegacyEvidenceGateReconciler
{
    public function __construct(
        private EngineeringHumanDecisionStoreInterface $decisions,
        private EngineeringWorkflowStoreInterface $workflows,
        private EngineeringFeatureStoreInterface $features,
        private EngineeringRepositoryGatewayInterface $repository,
        private EngineeringWorkflowLockInterface $lock,
        private EngineeringExecutionJournal $journal,
        private EngineeringWorkflowCoordinator $coordinator = new EngineeringWorkflowCoordinator(),
        private EngineeringArchitectEvidenceAuthorization $policy = new EngineeringArchitectEvidenceAuthorization(),
    ) {}

}
