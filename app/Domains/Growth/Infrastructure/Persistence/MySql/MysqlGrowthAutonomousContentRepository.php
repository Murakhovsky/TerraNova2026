<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\Persistence\MySql;

use Domains\Growth\Application\Contract\GrowthAutonomousContentRepositoryInterface;
use InvalidArgumentException;
use PDO;
use PDOException;

final readonly class MysqlGrowthAutonomousContentRepository implements GrowthAutonomousContentRepositoryInterface
{
    public function __construct(private PDO $connection){}

    public function createRun(
        string $organizationId,string $runId,string $candidateId,string $recommendationId,array $context,
        string $promptVersion,string $schemaVersion,int $actorId
    ):void {
        $recover=$this->connection->prepare(
            'UPDATE tn_growth_engagement_content_runs
             SET status=\'failed\',active_key=NULL,error_summary=\'Generation lease expired before completion.\',
                 finished_at=NOW(6)
             WHERE organization_id=:organization_id AND recommendation_id=:recommendation_id
               AND status=\'running\' AND active_key=\'active\'
               AND started_at<DATE_SUB(NOW(6),INTERVAL 15 MINUTE)'
        );
        $recover->execute(['organization_id'=>$organizationId,'recommendation_id'=>$recommendationId]);

        $statement=$this->connection->prepare(
            'INSERT INTO tn_growth_engagement_content_runs
             (organization_id,run_id,candidate_id,recommendation_id,status,active_key,context_snapshot_json,prompt_version,schema_version,started_at,created_by,created_at)
             VALUES(:organization_id,:run_id,:candidate_id,:recommendation_id,\'running\',\'active\',:context_snapshot_json,:prompt_version,:schema_version,NOW(6),:created_by,NOW(6))'
        );
        try{
            $statement->execute([
                'organization_id'=>$organizationId,'run_id'=>$runId,'candidate_id'=>$candidateId,'recommendation_id'=>$recommendationId,
                'context_snapshot_json'=>$this->encode($context),'prompt_version'=>$promptVersion,'schema_version'=>$schemaVersion,'created_by'=>$actorId,
            ]);
        }catch(PDOException $error){
            if((string)$error->getCode()==='23000'){
                throw new InvalidArgumentException('Growth content generation is already active for this recommendation.',0,$error);
            }
            throw $error;
        }
    }

    public function completeRun(
        string $organizationId,string $runId,string $draftId,string $provider,string $model,
        ?int $inputTokens,?int $outputTokens,?float $costAmount,?string $costCurrency
    ):void {
        $statement=$this->connection->prepare(
            'UPDATE tn_growth_engagement_content_runs
             SET status=\'completed\',active_key=NULL,draft_id=:draft_id,provider=:provider,model=:model,input_tokens=:input_tokens,
                 output_tokens=:output_tokens,cost_amount=:cost_amount,cost_currency=:cost_currency,finished_at=NOW(6)
             WHERE organization_id=:organization_id AND run_id=:run_id AND status=\'running\''
        );
        $statement->execute([
            'draft_id'=>$draftId,'provider'=>$provider,'model'=>$model,'input_tokens'=>$inputTokens,'output_tokens'=>$outputTokens,
            'cost_amount'=>$costAmount,'cost_currency'=>$costCurrency,'organization_id'=>$organizationId,'run_id'=>$runId,
        ]);
        if($statement->rowCount()!==1)throw new InvalidArgumentException('Growth content run could not be completed.');
    }

    public function failRun(string $organizationId,string $runId,string $errorSummary):void
    {
        $statement=$this->connection->prepare(
            'UPDATE tn_growth_engagement_content_runs
             SET status=\'failed\',active_key=NULL,error_summary=:error_summary,finished_at=NOW(6)
             WHERE organization_id=:organization_id AND run_id=:run_id AND status=\'running\''
        );
        $statement->execute(['error_summary'=>$errorSummary,'organization_id'=>$organizationId,'run_id'=>$runId]);
    }

    public function viewRun(string $organizationId,string $runId):?array
    {
        $row=$this->one(
            'SELECT organization_id,run_id,candidate_id,recommendation_id,status,draft_id,provider,model,input_tokens,output_tokens,
                    cost_amount,cost_currency,error_summary,started_at,finished_at,created_by,created_at
             FROM tn_growth_engagement_content_runs WHERE organization_id=:organization_id AND run_id=:run_id LIMIT 1',
            ['organization_id'=>$organizationId,'run_id'=>$runId],
        );
        if($row===null)return null;
        foreach(['input_tokens','output_tokens','created_by'] as $field)$row[$field]=$row[$field]===null?null:(int)$row[$field];
        $row['cost_amount']=$row['cost_amount']===null?null:(float)$row['cost_amount'];
        return $row;
    }

    public function latestReviewProfile(string $organizationId):?array
    {
        $row=$this->one(
            'SELECT organization_id,profile_id,revision,email_mode,linkedin_mode,phone_mode,min_draft_confidence,max_body_chars,
                    reason,created_by,created_at
             FROM tn_growth_engagement_content_review_profiles
             WHERE organization_id=:organization_id ORDER BY revision DESC LIMIT 1',
            ['organization_id'=>$organizationId],
        );
        if($row===null)return null;
        $row['revision']=(int)$row['revision'];$row['min_draft_confidence']=(float)$row['min_draft_confidence'];
        $row['max_body_chars']=(int)$row['max_body_chars'];$row['created_by']=(int)$row['created_by'];
        $row['channel_modes']=['email'=>(string)$row['email_mode'],'linkedin'=>(string)$row['linkedin_mode'],'phone'=>(string)$row['phone_mode']];
        return $row;
    }

    public function appendReviewProfile(array $profile):void
    {
        $statement=$this->connection->prepare(
            'INSERT INTO tn_growth_engagement_content_review_profiles
             (organization_id,profile_id,revision,email_mode,linkedin_mode,phone_mode,min_draft_confidence,max_body_chars,reason,created_by,created_at)
             VALUES(:organization_id,:profile_id,:revision,:email_mode,:linkedin_mode,:phone_mode,:min_draft_confidence,:max_body_chars,:reason,:created_by,:created_at)'
        );
        $modes=$profile['channel_modes'];
        $statement->execute([
            'organization_id'=>$profile['organization_id'],'profile_id'=>$profile['profile_id'],'revision'=>$profile['revision'],
            'email_mode'=>$modes['email'],'linkedin_mode'=>$modes['linkedin'],'phone_mode'=>$modes['phone'],
            'min_draft_confidence'=>$profile['min_draft_confidence'],'max_body_chars'=>$profile['max_body_chars'],
            'reason'=>$profile['reason'],'created_by'=>$profile['created_by'],'created_at'=>$profile['created_at'],
        ]);
    }

    public function appendDraft(array $draft):void
    {
        $statement=$this->connection->prepare(
            'INSERT INTO tn_growth_engagement_content_drafts
             (organization_id,draft_id,run_id,recommendation_id,candidate_id,revision,channel,body,evidence_ids_json,risk_flags_json,
              confidence,provider,model,prompt_version,schema_version,status,review_code,decision_reason,generated_by,decided_by,
              created_at,decided_at,updated_at)
             VALUES(:organization_id,:draft_id,:run_id,:recommendation_id,:candidate_id,:revision,:channel,:body,:evidence_ids_json,:risk_flags_json,
                    :confidence,:provider,:model,:prompt_version,:schema_version,:status,:review_code,:decision_reason,:generated_by,:decided_by,
                    :created_at,:decided_at,:updated_at)'
        );
        $statement->execute([
            'organization_id'=>$draft['organization_id'],'draft_id'=>$draft['draft_id'],'run_id'=>$draft['run_id'],
            'recommendation_id'=>$draft['recommendation_id'],'candidate_id'=>$draft['candidate_id'],'revision'=>$draft['revision'],
            'channel'=>$draft['channel'],'body'=>$draft['body'],'evidence_ids_json'=>$this->encode($draft['evidence_ids']),
            'risk_flags_json'=>$this->encode($draft['risk_flags']),'confidence'=>$draft['confidence'],'provider'=>$draft['provider'],
            'model'=>$draft['model'],'prompt_version'=>$draft['prompt_version'],'schema_version'=>$draft['schema_version'],
            'status'=>$draft['status'],'review_code'=>$draft['review_code'],'decision_reason'=>$draft['decision_reason'],
            'generated_by'=>$draft['generated_by'],'decided_by'=>$draft['decided_by'],'created_at'=>$draft['created_at'],
            'decided_at'=>$draft['decided_at'],'updated_at'=>$draft['updated_at'],
        ]);
    }

    public function latestDraft(string $organizationId,string $recommendationId):?array
    {
        return $this->draftRow(
            'SELECT * FROM tn_growth_engagement_content_drafts
             WHERE organization_id=:organization_id AND recommendation_id=:recommendation_id
             ORDER BY revision DESC LIMIT 1',
            ['organization_id'=>$organizationId,'recommendation_id'=>$recommendationId],
        );
    }

    public function viewDraft(string $organizationId,string $draftId):?array
    {
        return $this->draftRow(
            'SELECT * FROM tn_growth_engagement_content_drafts
             WHERE organization_id=:organization_id AND draft_id=:draft_id LIMIT 1',
            ['organization_id'=>$organizationId,'draft_id'=>$draftId],
        );
    }

    public function lockDraft(string $organizationId,string $draftId):array
    {
        return $this->draftRow(
            'SELECT * FROM tn_growth_engagement_content_drafts
             WHERE organization_id=:organization_id AND draft_id=:draft_id LIMIT 1 FOR UPDATE',
            ['organization_id'=>$organizationId,'draft_id'=>$draftId],
        )??throw new InvalidArgumentException('Growth content draft was not found.');
    }

    public function decideDraft(string $organizationId,string $draftId,string $status,string $reason,int $actorId,string $reviewCode):void
    {
        $statement=$this->connection->prepare(
            'UPDATE tn_growth_engagement_content_drafts
             SET status=:status,review_code=:review_code,decision_reason=:decision_reason,decided_by=:decided_by,
                 decided_at=NOW(6),updated_at=NOW(6)
             WHERE organization_id=:organization_id AND draft_id=:draft_id AND status=\'pending_review\''
        );
        $statement->execute([
            'status'=>$status,'review_code'=>$reviewCode,'decision_reason'=>$reason,'decided_by'=>$actorId,
            'organization_id'=>$organizationId,'draft_id'=>$draftId,
        ]);
        if($statement->rowCount()!==1)throw new InvalidArgumentException('Growth content draft is no longer pending review.');
    }

    public function draftCandidates(string $organizationId,int $limit):array
    {
        $limit=max(1,min(100,$limit));
        $statement=$this->connection->prepare(
            'SELECT r.recommendation_id,r.candidate_id,r.channel,r.confidence,r.status
             FROM tn_growth_engagement_recommendations r
             INNER JOIN (
               SELECT organization_id,MAX(revision) AS revision FROM tn_growth_engagement_autonomy_profiles GROUP BY organization_id
             ) a_latest ON a_latest.organization_id=r.organization_id
             INNER JOIN tn_growth_engagement_autonomy_profiles autonomy
               ON autonomy.organization_id=a_latest.organization_id AND autonomy.revision=a_latest.revision
             INNER JOIN (
               SELECT organization_id,MAX(revision) AS revision FROM tn_growth_engagement_activation_profiles GROUP BY organization_id
             ) x_latest ON x_latest.organization_id=r.organization_id
             INNER JOIN tn_growth_engagement_activation_profiles activation
               ON activation.organization_id=x_latest.organization_id AND activation.revision=x_latest.revision
             LEFT JOIN (
               SELECT p.organization_id,p.email_mode,p.linkedin_mode,p.phone_mode
               FROM tn_growth_engagement_content_review_profiles p
               INNER JOIN (
                 SELECT organization_id,MAX(revision) AS revision
                 FROM tn_growth_engagement_content_review_profiles GROUP BY organization_id
               ) latest ON latest.organization_id=p.organization_id AND latest.revision=p.revision
             ) review ON review.organization_id=r.organization_id
             LEFT JOIN tn_growth_engagement_execution_links e
               ON e.organization_id=r.organization_id AND e.recommendation_id=r.recommendation_id
             LEFT JOIN (
               SELECT DISTINCT organization_id,recommendation_id FROM tn_growth_engagement_autonomy_payloads
             ) payload ON payload.organization_id=r.organization_id AND payload.recommendation_id=r.recommendation_id
             LEFT JOIN (
               SELECT DISTINCT organization_id,recommendation_id FROM tn_growth_engagement_content_drafts
             ) draft ON draft.organization_id=r.organization_id AND draft.recommendation_id=r.recommendation_id
             WHERE r.organization_id=:organization_id
               AND autonomy.enabled=1
               AND r.channel IN (\'email\',\'linkedin\',\'phone\')
               AND r.confidence>=autonomy.min_confidence
               AND JSON_CONTAINS(autonomy.allowed_channels_json,JSON_QUOTE(r.channel))
               AND JSON_CONTAINS(autonomy.allowed_statuses_json,JSON_QUOTE(r.status))
               AND CASE r.channel
                     WHEN \'email\' THEN activation.email_mode
                     WHEN \'linkedin\' THEN activation.linkedin_mode
                     WHEN \'phone\' THEN activation.phone_mode
                     ELSE \'blocked\'
                   END=\'auto\'
               AND COALESCE(CASE r.channel
                     WHEN \'email\' THEN review.email_mode
                     WHEN \'linkedin\' THEN review.linkedin_mode
                     WHEN \'phone\' THEN review.phone_mode
                     ELSE \'blocked\'
                   END,\'human_review\')<>\'blocked\'
               AND e.execution_id IS NULL AND payload.recommendation_id IS NULL AND draft.recommendation_id IS NULL
               AND NOT EXISTS (
                 SELECT 1 FROM tn_growth_learning_bindings b
                 WHERE b.organization_id=r.organization_id AND b.candidate_id=r.candidate_id
                   AND b.source_domain=\'sales\' AND b.reference_type=\'sales_deal\'
               )
             ORDER BY r.created_at,r.recommendation_id
             LIMIT '.$limit
        );
        $statement->execute(['organization_id'=>$organizationId]);
        $rows=$statement->fetchAll(PDO::FETCH_ASSOC)?:[];
        foreach($rows as &$row)$row['confidence']=(float)$row['confidence'];
        unset($row);
        return array_values($rows);
    }

    /** @return array<string,mixed>|null */
    private function draftRow(string $sql,array $params):?array
    {
        $row=$this->one($sql,$params);
        if($row===null)return null;
        $row['revision']=(int)$row['revision'];$row['confidence']=(float)$row['confidence'];
        $row['generated_by']=(int)$row['generated_by'];$row['decided_by']=$row['decided_by']===null?null:(int)$row['decided_by'];
        $row['evidence_ids']=$this->decodeList((string)$row['evidence_ids_json']);
        $row['risk_flags']=$this->decodeList((string)$row['risk_flags_json']);
        unset($row['evidence_ids_json'],$row['risk_flags_json']);
        return $row;
    }

    private function one(string $sql,array $params):?array
    {
        $statement=$this->connection->prepare($sql);$statement->execute($params);
        $row=$statement->fetch(PDO::FETCH_ASSOC);
        return $row===false?null:$row;
    }

    private function encode(array $value):string
    {
        return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @return list<string> */
    private function decodeList(string $json):array
    {
        $value=json_decode($json,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($value)||!array_is_list($value))throw new InvalidArgumentException('Stored Growth content list is invalid.');
        return array_values(array_map('strval',$value));
    }
}
