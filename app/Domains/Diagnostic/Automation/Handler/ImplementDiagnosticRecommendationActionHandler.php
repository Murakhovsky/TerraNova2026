<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Automation\Handler;

use Kernel\Action\Action;
use Kernel\Action\Contract\ActionHandlerInterface;
use Kernel\Action\ExecutionResult;

final readonly class ImplementDiagnosticRecommendationActionHandler implements ActionHandlerInterface
{
    public function supports(string $actionType): bool
    {
        return $actionType === 'IMPLEMENT_DIAGNOSTIC_RECOMMENDATION';
    }

    public function execute(Action $action): ExecutionResult
    {
        if(!$this->supports($action->type)){
            return ExecutionResult::failure('Unsupported diagnostic action type.');
        }

        return ExecutionResult::success([
            'recommendation_id'=>$action->sourceId,
            'implementation_plan'=>array_values($action->parameters['actions']??[]),
            'success_metrics'=>array_values($action->parameters['success_metrics']??[]),
            'owner_id'=>$action->parameters['owner_id']??null,
            'owner_role'=>$action->parameters['owner_role']??null,
            'due_at'=>$action->parameters['due_at']??null,
            'workflow_code'=>$action->parameters['workflow_code']??null,
            'dependencies'=>array_values($action->parameters['dependencies']??[]),
            'expected_outcome'=>$action->parameters['expected_outcome']??null,
            'execution_kind'=>'diagnostic_recommendation_plan',
        ]);
    }
}
