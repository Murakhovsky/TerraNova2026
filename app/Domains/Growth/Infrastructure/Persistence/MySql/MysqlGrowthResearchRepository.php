<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Persistence\MySql;

use DateTimeImmutable;
use Domains\Growth\Application\Contract\GrowthResearchRepositoryInterface;
use Domains\Growth\Domain\ResearchProposal;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;
use PDO;

final readonly class MysqlGrowthResearchRepository implements GrowthResearchRepositoryInterface
{
    public function __construct(private PDO $connection) {}

    public function createRun(
        string $organizationId,string $runId,string $candidateId,string $promptVersion,string $schemaVersion,
        array $contextSnapshot,int $actorId
    ): void {
        $this->execute(
            'INSERT INTO tn_growth_research_runs
             (organization_id,run_id,candidate_id,status,prompt_version,schema_version,context_snapshot_json,created_by,started_at)
             VALUES(:organization_id,:run_id,:candidate_id,\'running\',:prompt_version,:schema_version,:context_snapshot_json,:created_by,NOW(6))',
            [
                'organization_id'=>$organizationId,'run_id'=>$runId,'candidate_id'=>$candidateId,
                'prompt_version'=>$promptVersion,'schema_version'=>$schemaVersion,
                'context_snapshot_json'=>$this->encode($contextSnapshot),'created_by'=>$actorId,
            ],
        );
    }

    public function viewRun(string $organizationId,string $runId): ?array
    {
        $row=$this->one(
            'SELECT organization_id,run_id,candidate_id,status,prompt_version,schema_version,context_snapshot_json,proposal_id,
                    provider,model,input_tokens,output_tokens,cost_amount,cost_currency,error_summary,
                    started_at,finished_at,created_by,created_at
             FROM tn_growth_research_runs
             WHERE organization_id=:organization_id AND run_id=:run_id LIMIT 1',
            ['organization_id'=>$organizationId,'run_id'=>$runId],
        );
        if($row===null)return null;
        foreach(['input_tokens','output_tokens'] as $field)$row[$field]=$row[$field]===null?null:(int)$row[$field];
        $row['cost_amount']=$row['cost_amount']===null?null:(float)$row['cost_amount'];
        $context=json_decode((string)$row['context_snapshot_json'],true,512,JSON_THROW_ON_ERROR);
        if(!is_array($context)||array_is_list($context))throw new InvalidArgumentException('Stored Growth research context snapshot is invalid.');
        $row['context_snapshot']=$context;
        unset($row['context_snapshot_json']);
        return $row;
    }

    public function completeRun(
        string $organizationId,string $runId,string $proposalId,string $provider,string $model,
        ?int $inputTokens,?int $outputTokens,?float $costAmount,?string $costCurrency
    ): void {
        $statement=$this->connection->prepare(
            'UPDATE tn_growth_research_runs
             SET status=\'completed\',proposal_id=:proposal_id,provider=:provider,model=:model,
                 input_tokens=:input_tokens,output_tokens=:output_tokens,cost_amount=:cost_amount,
                 cost_currency=:cost_currency,finished_at=NOW(6)
             WHERE organization_id=:organization_id AND run_id=:run_id AND status=\'running\''
        );
        $statement->execute([
            'proposal_id'=>$proposalId,'provider'=>$provider,'model'=>$model,
            'input_tokens'=>$inputTokens,'output_tokens'=>$outputTokens,'cost_amount'=>$costAmount,
            'cost_currency'=>$costCurrency,'organization_id'=>$organizationId,'run_id'=>$runId,
        ]);
        if($statement->rowCount()!==1)throw new InvalidArgumentException('Growth research run could not be completed.');
    }

    public function failRun(string $organizationId,string $runId,string $errorSummary): void
    {
        $statement=$this->connection->prepare(
            'UPDATE tn_growth_research_runs
             SET status=\'failed\',error_summary=:error_summary,finished_at=NOW(6)
             WHERE organization_id=:organization_id AND run_id=:run_id AND status=\'running\''
        );
        $statement->execute([
            'error_summary'=>$errorSummary,'organization_id'=>$organizationId,'run_id'=>$runId,
        ]);
        if($statement->rowCount()!==1)throw new InvalidArgumentException('Growth research run could not be failed.');
    }

    public function createProposal(ResearchProposal $proposal,string $runId,int $actorId): void
    {
        $this->execute(
            'INSERT INTO tn_growth_research_proposals
             (organization_id,proposal_id,run_id,candidate_id,why_it_matters,problem_hypothesis,why_now,
              evidence_ids_json,counter_evidence_ids_json,assumptions_json,unknowns_json,confidence,
              provider,model,prompt_version,schema_version,proposed_at,created_by)
             VALUES(:organization_id,:proposal_id,:run_id,:candidate_id,:why_it_matters,:problem_hypothesis,:why_now,
                    :evidence_ids_json,:counter_evidence_ids_json,:assumptions_json,:unknowns_json,:confidence,
                    :provider,:model,:prompt_version,:schema_version,:proposed_at,:created_by)',
            [
                'organization_id'=>$proposal->organizationId->value(),'proposal_id'=>$proposal->id,'run_id'=>$runId,
                'candidate_id'=>$proposal->candidateId,'why_it_matters'=>$proposal->whyItMatters,
                'problem_hypothesis'=>$proposal->problemHypothesis,'why_now'=>$proposal->whyNow,
                'evidence_ids_json'=>$this->encode($proposal->evidenceIds),
                'counter_evidence_ids_json'=>$this->encode($proposal->counterEvidenceIds),
                'assumptions_json'=>$this->encode($proposal->assumptions),'unknowns_json'=>$this->encode($proposal->unknowns),
                'confidence'=>$proposal->confidence,'provider'=>$proposal->provider,'model'=>$proposal->model,
                'prompt_version'=>$proposal->promptVersion,'schema_version'=>$proposal->schemaVersion,
                'proposed_at'=>$proposal->proposedAt->format('Y-m-d H:i:s.u'),'created_by'=>$actorId,
            ],
        );
    }

    public function lockProposal(string $organizationId,string $proposalId): ResearchProposal
    {
        $row=$this->one(
            'SELECT organization_id,proposal_id,candidate_id,why_it_matters,problem_hypothesis,why_now,
                    evidence_ids_json,counter_evidence_ids_json,assumptions_json,unknowns_json,confidence,
                    provider,model,prompt_version,schema_version,proposed_at
             FROM tn_growth_research_proposals
             WHERE organization_id=:organization_id AND proposal_id=:proposal_id
             LIMIT 1 FOR UPDATE',
            ['organization_id'=>$organizationId,'proposal_id'=>$proposalId],
        );
        if($row===null)throw new InvalidArgumentException('Growth research proposal was not found.');
        return $this->hydrateProposal($row);
    }

    public function viewProposal(string $organizationId,string $proposalId): ?array
    {
        $row=$this->one(
            'SELECT organization_id,proposal_id,run_id,candidate_id,why_it_matters,problem_hypothesis,why_now,
                    evidence_ids_json,counter_evidence_ids_json,assumptions_json,unknowns_json,confidence,
                    provider,model,prompt_version,schema_version,proposed_at,accepted_at,accepted_by,created_by,created_at
             FROM tn_growth_research_proposals
             WHERE organization_id=:organization_id AND proposal_id=:proposal_id LIMIT 1',
            ['organization_id'=>$organizationId,'proposal_id'=>$proposalId],
        );
        return $this->hydrateProposalRow($row);
    }

    public function latestProposal(string $organizationId,string $candidateId): ?array
    {
        $row=$this->one(
            'SELECT organization_id,proposal_id,run_id,candidate_id,why_it_matters,problem_hypothesis,why_now,
                    evidence_ids_json,counter_evidence_ids_json,assumptions_json,unknowns_json,confidence,
                    provider,model,prompt_version,schema_version,proposed_at,accepted_at,accepted_by,created_by,created_at
             FROM tn_growth_research_proposals
             WHERE organization_id=:organization_id AND candidate_id=:candidate_id
             ORDER BY proposed_at DESC,proposal_id DESC LIMIT 1',
            ['organization_id'=>$organizationId,'candidate_id'=>$candidateId],
        );
        return $this->hydrateProposalRow($row);
    }

    public function acceptProposal(string $organizationId,string $proposalId,int $actorId): void
    {
        $statement=$this->connection->prepare(
            'UPDATE tn_growth_research_proposals
             SET accepted_at=NOW(6),accepted_by=:accepted_by
             WHERE organization_id=:organization_id AND proposal_id=:proposal_id AND accepted_at IS NULL'
        );
        $statement->execute([
            'accepted_by'=>$actorId,'organization_id'=>$organizationId,'proposal_id'=>$proposalId,
        ]);
        if($statement->rowCount()!==1)throw new InvalidArgumentException('Growth research proposal could not be accepted.');
    }

    /** @param array<string,mixed> $row */
    private function hydrateProposal(array $row): ResearchProposal
    {
        return new ResearchProposal(
            (string)$row['proposal_id'],OrganizationId::fromString((string)$row['organization_id']),
            (string)$row['candidate_id'],(string)$row['why_it_matters'],(string)$row['problem_hypothesis'],
            (string)$row['why_now'],$this->decodeList((string)$row['evidence_ids_json']),
            $this->decodeList((string)$row['counter_evidence_ids_json']),
            $this->decodeList((string)$row['assumptions_json']),$this->decodeList((string)$row['unknowns_json']),
            (float)$row['confidence'],(string)$row['provider'],(string)$row['model'],
            (string)$row['prompt_version'],(string)$row['schema_version'],new DateTimeImmutable((string)$row['proposed_at']),
        );
    }

    /** @param array<string,mixed>|null $row @return array<string,mixed>|null */
    private function hydrateProposalRow(?array $row): ?array
    {
        if($row===null)return null;
        foreach([
            'evidence_ids_json'=>'evidence_ids','counter_evidence_ids_json'=>'counter_evidence_ids',
            'assumptions_json'=>'assumptions','unknowns_json'=>'unknowns',
        ] as $column=>$target){
            $row[$target]=$this->decodeList((string)$row[$column]);
            unset($row[$column]);
        }
        $row['confidence']=(float)$row['confidence'];
        return $row;
    }

    private function execute(string $sql,array $params): void
    {
        $statement=$this->connection->prepare($sql);
        $statement->execute($params);
    }

    private function one(string $sql,array $params): ?array
    {
        $statement=$this->connection->prepare($sql);
        $statement->execute($params);
        $row=$statement->fetch(PDO::FETCH_ASSOC);
        return $row===false?null:$row;
    }

    private function encode(array $value): string
    {
        return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }

    /** @return list<string> */
    private function decodeList(string $json): array
    {
        $value=json_decode($json,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($value)||!array_is_list($value))throw new InvalidArgumentException('Stored Growth research list is invalid.');
        return array_values(array_map('strval',$value));
    }
}
