<?php
declare(strict_types=1);

namespace App\Persistence\Workflow;

use Doctrine\DBAL\Connection;
use DomainException;
use Platform\Workflow\Contract\WorkflowNonActionEvidenceAdapterInterface;
use Platform\Workflow\Model\WorkflowRunIdentity;
use Platform\Workflow\Service\WorkflowDefinitionFingerprint;

/** Authenticates an immutable, validated result in the Research Lab read model. */
final readonly class CapitalResearchNonActionEvidence implements WorkflowNonActionEvidenceAdapterInterface
{
    public function __construct(private Connection $db) {}

    public function kind(): string { return 'capital_markets.research_result.validated.v1'; }
    public function ownerDomain(): string { return 'capital_markets'; }

    public function assertVerified(
        WorkflowRunIdentity $identity,
        string $stepId,
        string $reference,
        array $evidence,
    ): void {
        $result=$evidence['result_id']??null;
        $experiment=$evidence['experiment_id']??null;
        if(!is_string($result) || !preg_match('/^[a-zA-Z0-9_.:-]{1,190}$/D',$result)
            || !is_string($experiment) || !preg_match('/^[a-zA-Z0-9_.:-]{1,190}$/D',$experiment)) {
            throw new DomainException('Research evidence experiment/result identity invalid.');
        }
        $row=$this->db->fetchAssociative(
            'SELECT x.status,x.record_json,x.created_at AS result_created,
                    r.created_at AS run_started,st.updated_at AS step_completed
             FROM tn_capital_market_research_results x
             JOIN cos_workflow_runs r ON r.organization_id=x.organization_id
             JOIN cos_workflow_steps st
               ON st.organization_id=r.organization_id AND st.run_id=r.run_id
             WHERE x.organization_id=:org AND x.result_id=:result
               AND x.experiment_id=:experiment
               AND r.run_id=:run AND st.step_id=:step',
            ['org'=>$identity->organizationId,'result'=>$result,
             'experiment'=>$experiment,'run'=>$identity->runId,'step'=>$stepId],
        );
        if(!$row || strtoupper((string)$row['status'])!=='VALIDATED'
            || $row['result_created'] < $row['run_started']
            || $row['result_created'] > $row['step_completed']) {
            throw new DomainException('Research Lab has no fresh validated result for the pinned experiment.');
        }
        $record=json_decode((string)$row['record_json'],true,512,JSON_THROW_ON_ERROR);
        if(!is_array($record) || ($record['result_id']??null)!==$result
            || ($record['experiment_id']??null)!==$experiment
            || strtoupper((string)($record['status']??''))!=='VALIDATED'
            || !is_array($record['financial_metrics']??null)
            || !is_array($record['risk_metrics']??null)
            || !is_array($record['data_quality']??null)) {
            throw new DomainException('Stored Research result fails independent record validation.');
        }
        $expected='capital_markets:research:validated:v1:'.$result.':'
            .WorkflowDefinitionFingerprint::digest([$record]);
        if(!hash_equals($expected,$reference)) {
            throw new DomainException('Research evidence fingerprint does not match authoritative record.');
        }
    }
}
