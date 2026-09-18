<?php
declare(strict_types=1); namespace App\Application\Diagnostic\Query;
use Domains\Diagnostic\Application\Contract\DiagnosticRuntimeRepositoryInterface; use Kernel\Application\Query\QueryHandlerInterface;
final readonly class GetDiagnosticRecommendationsQueryHandler implements QueryHandlerInterface
{
    public function __construct(private DiagnosticRuntimeRepositoryInterface $runtime){}
    public function __invoke(GetDiagnosticRecommendationsQuery $q):array
    {
        $items=[]; foreach($this->runtime->recommendations($q->organizationId->value(),$q->sessionId) as $row){$p=is_array($row['payload']??null)?$row['payload']:[];$items[]=$p+['status'=>$row['status']??($p['status']??null),'action_id'=>$row['action_id']??null];} return ['items'=>$items];
    }
}
