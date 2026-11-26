<?php
declare(strict_types=1);
namespace App\Persistence\Federation;

use Doctrine\DBAL\Connection;
use DomainException;
use Domains\Federation\Application\Contract\FederationTrustedActionOutcomeReadPort;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

/** Federation-owned historical read model for an exact completed canonical Action. */
final readonly class FederationTrustedActionOutcomeReader implements FederationTrustedActionOutcomeReadPort
{
    public function __construct(private Connection $db){}
    public function read(TenantContext $actor,string $runId,string $evaluationId,string $capability):array
    {
        if(!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE)
            ||!preg_match('/^[a-z0-9][a-z0-9_:-]{0,63}$/D',$runId)||!preg_match('/^[a-z0-9][a-z0-9_:-]{0,63}$/D',$evaluationId)
            ||!preg_match('/^[a-z][a-z0-9_.-]{1,127}$/D',$capability))throw new DomainException('Historical Action outcome access denied.');
        $org=$actor->organizationId()->value();
        $rows=$this->db->fetchAllAssociative("SELECT e.evaluation_json,e.evaluated_at,e.goal_id,e.spec_version,w.actor_id,
            s.idempotency_key,s.result_reference,p.plan_json
            FROM cos_federation_evaluations e
            JOIN cos_workflow_runs w ON w.organization_id=e.organization_id
            JOIN cos_federation_plans p ON p.organization_id=w.organization_id AND p.plan_id=w.source_plan_id
                AND p.goal_id=e.goal_id AND p.spec_version=e.spec_version
            JOIN cos_workflow_steps s ON s.organization_id=w.organization_id AND s.run_id=w.run_id
            JOIN cos_actions a ON a.organization_id=w.organization_id AND a.id=SUBSTRING(s.result_reference,8)
                AND a.idempotency_key=CONCAT('wf:',s.idempotency_key) AND a.type=s.capability_id AND a.status='COMPLETED'
            WHERE e.organization_id=:org AND BINARY e.organization_id=BINARY :exact_org AND e.evaluation_id=:evaluation
                AND e.result='satisfied' AND w.run_id=:run AND w.definition_id='federation.approved-plan' AND w.state='completed'
                AND p.state='approved' AND s.capability_id=:cap AND s.state='completed'
                AND JSON_UNQUOTE(JSON_EXTRACT(e.evaluation_json,'$.run_id'))=:evidence_run",
            ['org'=>$org,'exact_org'=>$org,'evaluation'=>$evaluationId,'run'=>$runId,'cap'=>$capability,'evidence_run'=>$runId]);
        if(count($rows)!==1||!preg_match('/^action:([a-f0-9]{32})$/D',(string)$rows[0]['result_reference'],$match)
            ||!preg_match('/^[a-f0-9]{64}$/D',(string)$rows[0]['idempotency_key']))
            throw new DomainException('Persisted trusted canonical Action outcome unavailable.');
        $row=$rows[0];
        return [...$row,'action_id'=>$match[1]];
    }
}
