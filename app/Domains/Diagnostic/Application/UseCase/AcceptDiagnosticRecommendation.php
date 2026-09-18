<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Application\UseCase;

use DateTimeImmutable;
use DomainException;
use Domains\Diagnostic\Model\Recommendation;
use Domains\Diagnostic\Report\RecommendationStatus;
use Kernel\Action\Action;
use Kernel\Action\ActionProposal;
use Kernel\Action\Service\ActionService;

final readonly class AcceptDiagnosticRecommendation
{
    public const WORKFLOW = 'diagnostic.recommendation.implementation';

    public function __construct(private ActionService $actions) {}

    public function execute(
        string $organizationId,
        string $diagnosticId,
        Recommendation $recommendation,
        ?int $ownerId = null,
        ?DateTimeImmutable $dueAt = null,
        string $workflowCode = self::WORKFLOW,
    ): Action {
        $workflowCode=trim($workflowCode);
        if($ownerId!==null && $ownerId<=0) throw new DomainException('Recommendation action owner must be positive.');
        if($workflowCode==='' || preg_match('/^[a-z][a-z0-9._:-]*$/',$workflowCode)!==1){
            throw new DomainException('Recommendation workflow code is invalid.');
        }

        $now=new DateTimeImmutable();
        $dueAt ??= $now->modify('+14 days');
        if($dueAt <= $now) throw new DomainException('Recommendation action due date must be in the future.');

        $recommendation->transitionTo(RecommendationStatus::Accepted);

        $parameters=[
            'actions'=>$recommendation->actions,
            'success_metrics'=>$recommendation->successMetrics,
            'owner_id'=>$ownerId,
            'owner_role'=>(string)($recommendation->details['owner_role']??'Sales Manager'),
            'due_at'=>$dueAt->format(DATE_ATOM),
            'workflow_code'=>$workflowCode,
            'dependencies'=>array_values($recommendation->details['dependencies']??[]),
            'expected_outcome'=>(string)($recommendation->details['expected_outcome']??$recommendation->impact),
        ];

        return $this->actions->propose(
            $organizationId,
            new ActionProposal(
                'IMPLEMENT_DIAGNOSTIC_RECOMMENDATION',
                'diagnostic_recommendation',
                $recommendation->id,
                $parameters,
                'DIAGNOSTIC_RECOMMENDATION',
                $recommendation->id,
                'APPROVAL_REQUIRED',
                'MEDIUM',
                hash('sha256',$organizationId.':'.$diagnosticId.':'.$recommendation->id),
                [
                    'diagnostic_id'=>$diagnosticId,
                    'owner_id'=>$ownerId,
                    'due_at'=>$dueAt->format(DATE_ATOM),
                    'workflow_code'=>$workflowCode,
                ],
            ),
            $diagnosticId,
        );
    }
}
