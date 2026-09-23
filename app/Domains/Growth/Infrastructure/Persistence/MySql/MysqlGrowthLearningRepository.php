<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Persistence\MySql;

use Domains\Growth\Application\Contract\GrowthLearningRepositoryInterface;
use Domains\Growth\Domain\GrowthOutcomeObservation;
use InvalidArgumentException;
use PDO;

final readonly class MysqlGrowthLearningRepository implements GrowthLearningRepositoryInterface
{
    public function __construct(private PDO $connection){}

    public function bindExternalSubject(
        string $organizationId,string $candidateId,string $sourceDomain,string $referenceType,string $referenceId,
        string $sourceEventId
    ):void {
        $statement=$this->connection->prepare(
            'INSERT IGNORE INTO tn_growth_learning_bindings
             (organization_id,candidate_id,source_domain,reference_type,reference_id,source_event_id,created_at)
             VALUES(:organization_id,:candidate_id,:source_domain,:reference_type,:reference_id,:source_event_id,NOW(6))'
        );
        $statement->execute([
            'organization_id'=>$organizationId,'candidate_id'=>$candidateId,'source_domain'=>$sourceDomain,
            'reference_type'=>$referenceType,'reference_id'=>$referenceId,'source_event_id'=>$sourceEventId,
        ]);

        $bound=$this->candidateByExternalSubject($organizationId,$sourceDomain,$referenceType,$referenceId);
        if($bound!==$candidateId){
            throw new InvalidArgumentException('Growth learning external subject is already bound to another Candidate.');
        }
    }

    public function candidateByExternalSubject(
        string $organizationId,string $sourceDomain,string $referenceType,string $referenceId
    ):?string {
        $statement=$this->connection->prepare(
            'SELECT candidate_id FROM tn_growth_learning_bindings
             WHERE organization_id=:organization_id AND source_domain=:source_domain
               AND reference_type=:reference_type AND reference_id=:reference_id
             LIMIT 1'
        );
        $statement->execute([
            'organization_id'=>$organizationId,'source_domain'=>$sourceDomain,
            'reference_type'=>$referenceType,'reference_id'=>$referenceId,
        ]);
        $value=$statement->fetchColumn();
        return $value===false?null:(string)$value;
    }

    public function externalSubjectsForCandidate(
        string $organizationId,string $candidateId,string $sourceDomain,string $referenceType
    ):array {
        $statement=$this->connection->prepare(
            'SELECT reference_id FROM tn_growth_learning_bindings
             WHERE organization_id=:organization_id AND candidate_id=:candidate_id
               AND source_domain=:source_domain AND reference_type=:reference_type
             ORDER BY created_at,reference_id'
        );
        $statement->execute([
            'organization_id'=>$organizationId,'candidate_id'=>$candidateId,
            'source_domain'=>$sourceDomain,'reference_type'=>$referenceType,
        ]);
        return array_values(array_map('strval',$statement->fetchAll(PDO::FETCH_COLUMN)?:[]));
    }

    public function recordOutcome(GrowthOutcomeObservation $outcome):void
    {
        $statement=$this->connection->prepare(
            'INSERT IGNORE INTO tn_growth_outcomes
             (organization_id,outcome_id,candidate_id,source_domain,source_event_id,reference_type,reference_id,
              outcome_type,reason_code,reason_text,economic_value,currency,observed_at,created_at)
             VALUES(:organization_id,:outcome_id,:candidate_id,:source_domain,:source_event_id,:reference_type,:reference_id,
                    :outcome_type,:reason_code,:reason_text,:economic_value,:currency,:observed_at,NOW(6))'
        );
        $statement->execute([
            'organization_id'=>$outcome->organizationId->value(),'outcome_id'=>$outcome->id,'candidate_id'=>$outcome->candidateId,
            'source_domain'=>$outcome->sourceDomain,'source_event_id'=>$outcome->sourceEventId,
            'reference_type'=>$outcome->referenceType,'reference_id'=>$outcome->referenceId,
            'outcome_type'=>$outcome->outcomeType->value,'reason_code'=>$outcome->reasonCode,'reason_text'=>$outcome->reasonText,
            'economic_value'=>$outcome->economicValue,'currency'=>$outcome->currency,
            'observed_at'=>$outcome->observedAt->format('Y-m-d H:i:s.u'),
        ]);

        $check=$this->connection->prepare(
            'SELECT candidate_id,outcome_type,reference_type,reference_id
             FROM tn_growth_outcomes
             WHERE organization_id=:organization_id AND source_event_id=:source_event_id LIMIT 1'
        );
        $check->execute([
            'organization_id'=>$outcome->organizationId->value(),'source_event_id'=>$outcome->sourceEventId,
        ]);
        $stored=$check->fetch(PDO::FETCH_ASSOC);
        if($stored===false
            ||(string)$stored['candidate_id']!==$outcome->candidateId
            ||(string)$stored['outcome_type']!==$outcome->outcomeType->value
            ||(string)$stored['reference_type']!==$outcome->referenceType
            ||(string)$stored['reference_id']!==$outcome->referenceId
        ){
            throw new InvalidArgumentException('Growth outcome source event conflicts with an existing learning observation.');
        }
    }

    public function outcomesForCandidate(string $organizationId,string $candidateId,int $limit=100):array
    {
        $limit=max(1,min(500,$limit));
        $statement=$this->connection->prepare(
            'SELECT outcome_id,candidate_id,source_domain,source_event_id,reference_type,reference_id,outcome_type,
                    reason_code,reason_text,economic_value,currency,observed_at,created_at
             FROM tn_growth_outcomes
             WHERE organization_id=:organization_id AND candidate_id=:candidate_id
             ORDER BY observed_at DESC,outcome_id DESC
             LIMIT '.$limit
        );
        $statement->execute(['organization_id'=>$organizationId,'candidate_id'=>$candidateId]);
        $rows=$statement->fetchAll(PDO::FETCH_ASSOC)?:[];
        foreach($rows as &$row){
            $row['economic_value']=$row['economic_value']===null?null:(float)$row['economic_value'];
        }
        unset($row);
        return $rows;
    }

    public function outcomeSummary(string $organizationId,string $candidateId):array
    {
        $countsStatement=$this->connection->prepare(
            'SELECT outcome_type,COUNT(*) AS total
             FROM tn_growth_outcomes
             WHERE organization_id=:organization_id AND candidate_id=:candidate_id
             GROUP BY outcome_type ORDER BY outcome_type'
        );
        $countsStatement->execute(['organization_id'=>$organizationId,'candidate_id'=>$candidateId]);
        $counts=[];
        foreach($countsStatement->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)$counts[(string)$row['outcome_type']]=(int)$row['total'];

        $valueStatement=$this->connection->prepare(
            'SELECT currency,SUM(economic_value) AS total_value
             FROM tn_growth_outcomes
             WHERE organization_id=:organization_id AND candidate_id=:candidate_id
               AND outcome_type=\\'won\\' AND economic_value IS NOT NULL
             GROUP BY currency ORDER BY currency'
        );
        $valueStatement->execute(['organization_id'=>$organizationId,'candidate_id'=>$candidateId]);
        $values=[];
        foreach($valueStatement->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $currency=(string)($row['currency']??'');
            if($currency!=='')$values[$currency]=(float)$row['total_value'];
        }

        $latest=$this->outcomesForCandidate($organizationId,$candidateId,1);
        return [
            'candidate_id'=>$candidateId,
            'counts'=>$counts,
            'won_value_by_currency'=>$values,
            'latest_outcome'=>$latest[0]??null,
        ];
    }
}
