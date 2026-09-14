<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Application\UseCase;
use Domains\Diagnostic\Model\Recommendation;
use Domains\Diagnostic\Report\RecommendationStatus;
use Kernel\Action\Action;
use Kernel\Action\ActionProposal;
use Kernel\Action\Service\ActionService;
final readonly class AcceptDiagnosticRecommendation
{
    public function __construct(private ActionService $actions){}
    public function execute(string $organizationId,string $diagnosticId,Recommendation $recommendation):Action{$recommendation->transitionTo(RecommendationStatus::Accepted);return $this->actions->propose($organizationId,new ActionProposal('IMPLEMENT_DIAGNOSTIC_RECOMMENDATION','diagnostic_recommendation',$recommendation->id,['actions'=>$recommendation->actions,'success_metrics'=>$recommendation->successMetrics],'DIAGNOSTIC_RECOMMENDATION',$recommendation->id,'APPROVAL_REQUIRED','MEDIUM',hash('sha256',$organizationId.':'.$diagnosticId.':'.$recommendation->id)),$diagnosticId);}
}
