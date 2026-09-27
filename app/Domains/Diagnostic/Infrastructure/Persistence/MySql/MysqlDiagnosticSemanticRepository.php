<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Infrastructure\Persistence\MySql;

use DateTimeImmutable;
use Domains\Diagnostic\Application\Contract\DiagnosticSemanticRepositoryInterface;
use Domains\Diagnostic\Model\Assessment;
use Domains\Diagnostic\Model\DiagnosticState;
use Domains\Diagnostic\Model\FactRevision;
use Domains\Diagnostic\Model\Hypothesis;
use Domains\Diagnostic\Report\RecommendationStatus;
use JsonException;
use PDO;
use RuntimeException;

final readonly class MysqlDiagnosticSemanticRepository implements DiagnosticSemanticRepositoryInterface
{
    public function __construct(private PDO $connection) {}

    public function appendFactRevision(string $organizationId, string $sessionId, FactRevision $revision, string $valueType): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO diagnostic_fact_revisions '
            . '(organization_id,session_id,fact_id,revision,value_json,value_type,truth_level,confidence,source,evidence_ids_json,supersedes_revision,reason,recorded_at) '
            . 'VALUES (:organization_id,:session_id,:fact_id,:revision,CAST(:value_json AS JSON),:value_type,:truth_level,:confidence,:source,CAST(:evidence_ids_json AS JSON),:supersedes_revision,:reason,:recorded_at)'
        );
        $statement->execute([
            'organization_id'=>$organizationId,
            'session_id'=>$sessionId,
            'fact_id'=>$revision->factId,
            'revision'=>$revision->revision,
            'value_json'=>$this->json($revision->newValue),
            'value_type'=>$valueType,
            'truth_level'=>$revision->truthLevel->value,
            'confidence'=>$revision->confidence,
            'source'=>$revision->source,
            'evidence_ids_json'=>$this->json($revision->evidenceIds),
            'supersedes_revision'=>$revision->supersedesRevision,
            'reason'=>$revision->reason,
            'recorded_at'=>$this->format($revision->createdAt),
        ]);
    }

    public function appendAssessmentRevision(string $organizationId,string $sessionId,string $assessmentId,int $revision,Assessment $assessment,array $upstreamIds,DateTimeImmutable $recordedAt): void
    {
        $statement=$this->connection->prepare(
            'INSERT INTO diagnostic_assessment_revisions '
            . '(organization_id,session_id,assessment_id,criterion_id,revision,status,score,severity,confidence,coverage,evidence_ids_json,upstream_ids_json,reason,recorded_at) '
            . 'VALUES (:organization_id,:session_id,:assessment_id,:criterion_id,:revision,:status,:score,:severity,:confidence,:coverage,CAST(:evidence_ids_json AS JSON),CAST(:upstream_ids_json AS JSON),:reason,:recorded_at)'
        );
        $statement->execute([
            'organization_id'=>$organizationId,'session_id'=>$sessionId,'assessment_id'=>$assessmentId,
            'criterion_id'=>$assessment->criterionId,'revision'=>$revision,'status'=>$assessment->status->value,
            'score'=>$assessment->score,'severity'=>$assessment->severity->value,'confidence'=>$assessment->confidence,
            'coverage'=>$assessment->coverage,'evidence_ids_json'=>$this->json($assessment->evidenceRefs),
            'upstream_ids_json'=>$this->json($upstreamIds),'reason'=>$assessment->reason,'recorded_at'=>$this->format($recordedAt),
        ]);
    }

    public function appendHypothesisRevision(string $organizationId,string $sessionId,Hypothesis $hypothesis,int $revision,array $causalPath,?string $policyId,DateTimeImmutable $recordedAt): void
    {
        $statement=$this->connection->prepare(
            'INSERT INTO diagnostic_hypothesis_revisions '
            . '(organization_id,session_id,hypothesis_id,revision,status,statement,confidence,supporting_evidence_json,contradicting_evidence_json,causal_path_json,policy_id,recorded_at) '
            . 'VALUES (:organization_id,:session_id,:hypothesis_id,:revision,:status,:statement,:confidence,CAST(:supporting AS JSON),CAST(:contradicting AS JSON),CAST(:causal_path AS JSON),:policy_id,:recorded_at)'
        );
        $statement->execute([
            'organization_id'=>$organizationId,'session_id'=>$sessionId,'hypothesis_id'=>$hypothesis->id,
            'revision'=>$revision,'status'=>$hypothesis->status->value,'statement'=>$hypothesis->statement,
            'confidence'=>$hypothesis->confidence,'supporting'=>$this->json($hypothesis->supportingEvidence),
            'contradicting'=>$this->json($hypothesis->contradictingEvidence),'causal_path'=>$this->json($causalPath),
            'policy_id'=>$policyId,'recorded_at'=>$this->format($recordedAt),
        ]);
    }

    public function appendRecommendationTransition(string $organizationId,string $sessionId,string $recommendationId,int $transitionNo,?RecommendationStatus $from,RecommendationStatus $to,?string $actorReference,?string $rationale,DateTimeImmutable $recordedAt): void
    {
        $statement=$this->connection->prepare(
            'INSERT INTO diagnostic_recommendation_transitions '
            . '(organization_id,session_id,recommendation_id,transition_no,from_status,to_status,actor_reference,rationale,recorded_at) '
            . 'VALUES (:organization_id,:session_id,:recommendation_id,:transition_no,:from_status,:to_status,:actor_reference,:rationale,:recorded_at)'
        );
        $statement->execute([
            'organization_id'=>$organizationId,'session_id'=>$sessionId,'recommendation_id'=>$recommendationId,
            'transition_no'=>$transitionNo,'from_status'=>$from?->value,'to_status'=>$to->value,
            'actor_reference'=>$actorReference,'rationale'=>$rationale,'recorded_at'=>$this->format($recordedAt),
        ]);
    }

    public function saveStateSnapshot(string $organizationId, DiagnosticState $state): void
    {
        $statement=$this->connection->prepare(
            'INSERT INTO diagnostic_state_snapshots '
            . '(organization_id,session_id,revision,pack_id,pack_version,state_json,input_ids_json,computed_at) '
            . 'VALUES (:organization_id,:session_id,:revision,:pack_id,:pack_version,CAST(:state_json AS JSON),CAST(:input_ids_json AS JSON),:computed_at)'
        );
        $statement->execute([
            'organization_id'=>$organizationId,'session_id'=>$state->diagnosticId,'revision'=>$state->revision,
            'pack_id'=>$state->packId,'pack_version'=>$state->packVersion,'state_json'=>$this->json($state),
            'input_ids_json'=>$this->json($state->inputIds),'computed_at'=>$this->format($state->computedAt),
        ]);
    }

    public function latestAssessmentStatuses(string $organizationId, string $sessionId): array
    {
        $statement = $this->connection->prepare(
            'SELECT a.criterion_id, a.status FROM diagnostic_assessment_revisions a '
            . 'INNER JOIN (SELECT criterion_id, MAX(revision) AS revision '
            . 'FROM diagnostic_assessment_revisions WHERE organization_id=:organization_id_sub AND session_id=:session_id_sub GROUP BY criterion_id) latest '
            . 'ON latest.criterion_id=a.criterion_id AND latest.revision=a.revision '
            . 'WHERE a.organization_id=:organization_id AND a.session_id=:session_id'
        );
        $statement->execute([
            'organization_id_sub'=>$organizationId,
            'session_id_sub'=>$sessionId,
            'organization_id'=>$organizationId,
            'session_id'=>$sessionId,
        ]);
        $out=[];
        foreach($statement->fetchAll(PDO::FETCH_ASSOC) as $row){
            $out[(string)$row['criterion_id']] = (string)$row['status'];
        }
        return $out;
    }

    private function format(DateTimeImmutable $value): string { return $value->format('Y-m-d H:i:s.u'); }

    private function json(mixed $value): string
    {
        try { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
        catch (JsonException $e) { throw new RuntimeException('Unable to serialize diagnostic semantic state.', 0, $e); }
    }
}
