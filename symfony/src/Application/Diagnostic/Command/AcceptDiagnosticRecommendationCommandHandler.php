<?php
declare(strict_types=1);

namespace App\Application\Diagnostic\Command;

use App\Application\Diagnostic\DiagnosticMutationAudit;
use Domains\Diagnostic\Application\Service\DiagnosticRuntimeService;
use DomainException;
use Kernel\Application\Command\CommandHandlerInterface;
use Kernel\Identity\Contract\IdentityResolverInterface;
use Kernel\Shared\Domain\UserId;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class AcceptDiagnosticRecommendationCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private DiagnosticRuntimeService $runtime,
        private DiagnosticMutationAudit $audit,

        private TransactionManagerInterface $transactions,
    ) {}

    public function __invoke(AcceptDiagnosticRecommendationCommand $command): array
    {
        return $this->transactions->transactional(function () use ($command): array {
            $org=$command->organizationId->value();
            $owner=$this->identities->resolve(UserId::fromString((string)$command->ownerId),$command->organizationId);
            if($owner===null){
                throw new DomainException('Recommendation action owner is not an active member of this organization.');
            }

            $result=$this->runtime->accept(
                $org,
                $command->sessionId,
                $command->recommendationId,
                $command->ownerId,
                $command->dueAt,
                $command->workflowCode,
            );
            if(($result['replayed']??false)!==true){
                $this->audit->record($org,$command->actorId,$command->correlationId,'diagnostic.recommendation_to_action',$command->sessionId,['recommendation_id'=>$command->recommendationId,'action_id'=>$result['action_id']??null]);
            }
            return $result;
        });
    }
}
