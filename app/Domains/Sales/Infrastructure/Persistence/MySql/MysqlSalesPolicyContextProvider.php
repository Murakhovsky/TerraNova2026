<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\Persistence\MySql;

use Kernel\Action\ActionProposal;
use Kernel\Policy\Contract\PolicyContextProviderInterface;
use PDO;

final readonly class MysqlSalesPolicyContextProvider implements PolicyContextProviderInterface
{
    public function __construct(private PDO $connection) {}

    public function context(string $organizationId, ActionProposal $proposal): array
    {
        $context = ['deal'=>['id'=>$proposal->targetId,'pipeline_id'=>null,'stage_id'=>null,'stage_code'=>null,'value'=>null,'source'=>null,'owner_id'=>null,'team_id'=>null,'priority'=>null], 'target_stage'=>['id'=>null,'code'=>null,'is_won'=>false,'is_lost'=>false]];
        if (in_array($proposal->targetType, ['deal','client_case'], true) && ctype_digit((string) $proposal->targetId)) {
            $statement=$this->connection->prepare('SELECT c.id,c.pipeline_id,c.stage_id,COALESCE(s.code,UPPER(c.stage)) stage_code,COALESCE(c.deal_value,c.budget_max,0) value,c.source,c.assigned_user_id owner_id,c.priority FROM tn_client_cases c LEFT JOIN sales_pipeline_stages s ON s.id=c.stage_id AND s.organization_id=c.organization_id WHERE c.organization_id=:organization_id AND c.id=:id LIMIT 1');
            $statement->execute(['organization_id'=>$organizationId,'id'=>$proposal->targetId]);
            $row=$statement->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) $context['deal']=[...$context['deal'],...$row,'value'=>(float)$row['value']];
        }
        $targetStageId=trim((string)($proposal->parameters['stage_id'] ?? $proposal->policyContext['target_stage']['id'] ?? ''));
        if ($targetStageId !== '') {
            $statement=$this->connection->prepare('SELECT id,code,is_won,is_lost FROM sales_pipeline_stages WHERE organization_id=:organization_id AND id=:id LIMIT 1');
            $statement->execute(['organization_id'=>$organizationId,'id'=>$targetStageId]);
            $row=$statement->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) $context['target_stage']=['id'=>(string)$row['id'],'code'=>(string)$row['code'],'is_won'=>(bool)$row['is_won'],'is_lost'=>(bool)$row['is_lost']];
        }
        if (!array_key_exists('confidence',$proposal->policyContext) && strtoupper($proposal->sourceType)==='AGENT') {
            $statement=$this->connection->prepare('SELECT confidence FROM cos_decisions WHERE organization_id=:organization_id AND source_type="AGENT" AND source_id=:source_id ORDER BY created_at DESC LIMIT 1');
            $statement->execute(['organization_id'=>$organizationId,'source_id'=>$proposal->sourceId]);
            $confidence=$statement->fetchColumn();
            if ($confidence !== false) $context['confidence']=(float)$confidence;
        }
        return $context;
    }
}
