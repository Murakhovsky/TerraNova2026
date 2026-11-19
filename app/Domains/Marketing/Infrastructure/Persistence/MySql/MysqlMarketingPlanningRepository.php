<?php
declare(strict_types=1);

namespace Domains\Marketing\Infrastructure\Persistence\MySql;

use DomainException;
use Domains\Marketing\Application\Contract\MarketingPlanningRepository;
use PDO;
use PDOException;
use Throwable;

/** Native Marketing planning ledger; tenant is present in every read, write and FK. */
final readonly class MysqlMarketingPlanningRepository implements MarketingPlanningRepository
{
    public function __construct(private PDO $connection){}

    public function createCreative(string $org,string $campaignId,string $actor,array $content,string $key):array
    {
        $hash=self::hash(['campaign'=>$campaignId,'actor'=>$actor,'creative'=>$content]);
        return $this->write($org,'creative_create',$key,$hash,function()use($org,$campaignId,$actor,$content):array {
            $campaign=$this->campaign($org,$campaignId,true);
            if($campaign['status']!=='DRAFT')throw new DomainException('Only DRAFT campaigns accept creative drafts.');
            $id='mkcr-'.bin2hex(random_bytes(12));
            $json=self::json($content);$digest=hash('sha256',$json);
            $this->connection->prepare("INSERT INTO marketing_creative_briefs
                (organization_id,id,campaign_id,brief_json,status,revision)
                VALUES (?,?,?,?,'DRAFT',1)")->execute([$org,$id,$campaignId,$json]);
            $this->connection->prepare('INSERT INTO marketing_creative_revisions
                (organization_id,creative_id,revision,actor_id,content_hash,content_json)
                VALUES (?,?,1,?,?,?)')->execute([$org,$id,$actor,$digest,$json]);
            return $this->creative($org,$id);
        });
    }

    public function reviseCreative(string $org,string $creativeId,string $actor,int $expectedRevision,array $content,string $key):array
    {
        $hash=self::hash(['creative'=>$creativeId,'revision'=>$expectedRevision,'actor'=>$actor,'content'=>$content]);
        return $this->write($org,'creative_revise',$key,$hash,function()use($org,$creativeId,$actor,$expectedRevision,$content):array {
            $brief=$this->lockedCreative($org,$creativeId);
            $campaign=$this->campaign($org,(string)$brief['campaign_id'],true);
            if($campaign['status']!=='DRAFT'||$brief['status']!=='DRAFT'){
                throw new DomainException('Creative revisions require a mutable internal draft.');
            }
            if((int)$brief['revision']!==$expectedRevision)throw new DomainException('Creative revision is stale.');
            $revision=$expectedRevision+1;
            $json=self::json($content);
            $this->connection->prepare('UPDATE marketing_creative_briefs SET brief_json=?,revision=? WHERE organization_id=? AND id=?')
                ->execute([$json,$revision,$org,$creativeId]);
            $this->connection->prepare('INSERT INTO marketing_creative_revisions
                (organization_id,creative_id,revision,actor_id,content_hash,content_json)
                VALUES (?,?,?,?,?,?)')->execute([$org,$creativeId,$revision,$actor,hash('sha256',$json),$json]);
            return $this->creative($org,$creativeId);
        });
    }

    public function creatives(string $org,string $campaignId):array
    {
        $this->campaign($org,$campaignId,false);
        $q=$this->connection->prepare('SELECT b.id,b.campaign_id,b.status,b.revision,b.created_at,b.updated_at,
            r.actor_id,r.content_hash,r.content_json
            FROM marketing_creative_briefs b
            JOIN marketing_creative_revisions r ON r.organization_id=b.organization_id
                AND r.creative_id=b.id AND r.revision=b.revision
            WHERE b.organization_id=? AND b.campaign_id=?
            ORDER BY b.updated_at DESC,b.id DESC LIMIT 100');
        $q->execute([$org,$campaignId]);
        return array_map(self::creativeProjection(...),$q->fetchAll(PDO::FETCH_ASSOC));
    }

    public function submitProposal(string $org,string $campaignId,string $creativeId,int $campaignRevision,int $creativeRevision,
        string $actor,array $proposal,string $key):array
    {
        $hash=self::hash(['campaign'=>$campaignId,'campaign_revision'=>$campaignRevision,'creative'=>$creativeId,
            'creative_revision'=>$creativeRevision,'actor'=>$actor,'proposal'=>$proposal]);
        return $this->write($org,'proposal_submit',$key,$hash,function()use(
            $org,$campaignId,$creativeId,$campaignRevision,$creativeRevision,$actor,$proposal
        ):array {
            $campaign=$this->campaign($org,$campaignId,true);
            $creative=$this->lockedCreative($org,$creativeId);
            if($campaign['status']!=='DRAFT'||(int)$campaign['revision']!==$campaignRevision){
                throw new DomainException('Analyst proposal targets a stale or closed Campaign revision.');
            }
            if($creative['campaign_id']!==$campaignId||$creative['status']!=='DRAFT'
                ||(int)$creative['revision']!==$creativeRevision){
                throw new DomainException('Analyst proposal targets a stale or foreign Creative revision.');
            }
            $id='mkpr-'.bin2hex(random_bytes(12));
            $evidence=self::json($proposal['evidence']);
            $this->connection->prepare("INSERT INTO marketing_analyst_proposals
                (organization_id,id,campaign_id,campaign_revision,creative_id,creative_revision,
                submitted_by,origin,recommendation,rationale,evidence_json,evidence_hash,status)
                VALUES (?,?,?,?,?,?,?,'HUMAN',?,?,?,?,'PROPOSED')")->execute([
                $org,$id,$campaignId,$campaignRevision,$creativeId,$creativeRevision,
                $actor,$proposal['recommendation'],$proposal['rationale'],$evidence,hash('sha256',$evidence)
            ]);
            return $this->proposal($org,$id);
        });
    }

    public function proposals(string $org,string $campaignId):array
    {
        $this->campaign($org,$campaignId,false);
        $q=$this->connection->prepare('SELECT id,campaign_id,campaign_revision,creative_id,creative_revision,
            submitted_by,origin,recommendation,rationale,evidence_json,evidence_hash,status,revision,
            reviewed_by,review_reason,reviewed_at,created_at,updated_at
            FROM marketing_analyst_proposals WHERE organization_id=? AND campaign_id=?
            ORDER BY created_at DESC,id DESC LIMIT 100');
        $q->execute([$org,$campaignId]);
        return array_map(self::proposalProjection(...),$q->fetchAll(PDO::FETCH_ASSOC));
    }

    public function reviewProposal(string $org,string $proposalId,string $actor,int $expectedRevision,array $review,string $key):array
    {
        $hash=self::hash(['proposal'=>$proposalId,'actor'=>$actor,'revision'=>$expectedRevision,'decision'=>$review]);
        return $this->write($org,'proposal_review',$key,$hash,function()use(
            $org,$proposalId,$actor,$expectedRevision,$review
        ):array {
            // Locks held in stable Campaign -> Creative -> Proposal order to avoid races with revisions.
            $q=$this->connection->prepare('SELECT campaign_id,creative_id FROM marketing_analyst_proposals WHERE organization_id=? AND id=?');
            $q->execute([$org,$proposalId]);$ref=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($ref))throw new DomainException('Marketing analyst proposal unavailable.');
            $campaign=$this->campaign($org,(string)$ref['campaign_id'],true);
            $creative=$this->lockedCreative($org,(string)$ref['creative_id']);
            $q=$this->connection->prepare('SELECT * FROM marketing_analyst_proposals WHERE organization_id=? AND id=? FOR UPDATE');
            $q->execute([$org,$proposalId]);$proposal=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($proposal))throw new DomainException('Marketing analyst proposal unavailable.');
            if($proposal['status']!=='PROPOSED'||(int)$proposal['revision']!==$expectedRevision){
                throw new DomainException('Proposal is already reviewed or revision has changed.');
            }
            if($proposal['submitted_by']===$actor)throw new DomainException('Reviewer must be independent from proposal author.');
            if($campaign['status']!=='DRAFT'||(int)$campaign['revision']!==(int)$proposal['campaign_revision']
                ||$creative['campaign_id']!==$campaign['id']
                ||$creative['status']!=='DRAFT'
                ||(int)$creative['revision']!==(int)$proposal['creative_revision']){
                throw new DomainException('Proposal evidence is stale; the Campaign or Creative has changed.');
            }
            $state=$review['decision']==='ACCEPT'?'ACCEPTED':'REJECTED';
            $this->connection->prepare('UPDATE marketing_analyst_proposals
                SET status=?,revision=revision+1,reviewed_by=?,review_reason=?,reviewed_at=UTC_TIMESTAMP(6)
                WHERE organization_id=? AND id=?')->execute([$state,$actor,$review['reason'],$org,$proposalId]);
            return $this->proposal($org,$proposalId);
        });
    }

    /** @return array<string,mixed> */
    private function campaign(string $org,string $id,bool $lock):array
    {
        $q=$this->connection->prepare('SELECT id,status,revision FROM marketing_campaigns WHERE organization_id=? AND id=?'.($lock?' FOR UPDATE':''));
        $q->execute([$org,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))throw new DomainException('Marketing Campaign unavailable.');
        return $row;
    }

    /** @return array<string,mixed> */
    private function lockedCreative(string $org,string $id):array
    {
        $q=$this->connection->prepare('SELECT * FROM marketing_creative_briefs WHERE organization_id=? AND id=? FOR UPDATE');
        $q->execute([$org,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))throw new DomainException('Marketing Creative unavailable.');
        return $row;
    }

    /** @return array<string,mixed> */
    private function creative(string $org,string $id):array
    {
        $q=$this->connection->prepare('SELECT b.id,b.campaign_id,b.status,b.revision,b.created_at,b.updated_at,
            r.actor_id,r.content_hash,r.content_json
            FROM marketing_creative_briefs b
            JOIN marketing_creative_revisions r ON r.organization_id=b.organization_id
                AND r.creative_id=b.id AND r.revision=b.revision
            WHERE b.organization_id=? AND b.id=?');
        $q->execute([$org,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))throw new DomainException('Marketing Creative receipt unavailable.');
        return self::creativeProjection($row);
    }

    /** @return array<string,mixed> */
    private function proposal(string $org,string $id):array
    {
        $q=$this->connection->prepare('SELECT id,campaign_id,campaign_revision,creative_id,creative_revision,
            submitted_by,origin,recommendation,rationale,evidence_json,evidence_hash,status,revision,
            reviewed_by,review_reason,reviewed_at,created_at,updated_at
            FROM marketing_analyst_proposals WHERE organization_id=? AND id=?');
        $q->execute([$org,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))throw new DomainException('Marketing Proposal receipt unavailable.');
        return self::proposalProjection($row);
    }

    /**
     * Transaction-wide idempotency receipt. The final result is resolved from the native record.
     * Unique-key races roll back the speculative write and reconcile the committed receipt.
     */
    private function write(string $org,string $op,string $key,string $hash,callable $body):array
    {
        if($this->connection->inTransaction())throw new DomainException('Nested Marketing planning transaction denied.');
        try {
            $this->connection->beginTransaction();
            $existing=$this->receipt($org,$op,$key,$hash,true);
            if($existing!==null){
                $result=$this->resolve($org,$op,$existing['id']);
                $this->connection->commit();
                return $result;
            }
            $result=$body();
            $this->connection->prepare('INSERT INTO marketing_planning_intents
                (organization_id,operation,idempotency_key,request_hash,record_id,record_revision)
                VALUES (?,?,?,?,?,?)')->execute([$org,$op,$key,$hash,$result['id'],$result['revision']]);
            $this->connection->commit();
            return $result;
        }catch(Throwable $e){
            if($this->connection->inTransaction())$this->connection->rollBack();
            if($e instanceof PDOException&&$e->getCode()==='23000'){
                $existing=$this->receipt($org,$op,$key,$hash,false);
                if($existing!==null)return $this->resolve($org,$op,$existing['id']);
            }
            throw $e;
        }
    }

    /** @return array{id:string,revision:int}|null */
    private function receipt(string $org,string $op,string $key,string $hash,bool $lock):?array
    {
        $q=$this->connection->prepare('SELECT request_hash,record_id,record_revision
            FROM marketing_planning_intents WHERE organization_id=? AND operation=? AND idempotency_key=?'.($lock?' FOR UPDATE':''));
        $q->execute([$org,$op,$key]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))return null;
        if(!hash_equals((string)$row['request_hash'],$hash))throw new DomainException('Marketing planning idempotency conflict.');
        return ['id'=>(string)$row['record_id'],'revision'=>(int)$row['record_revision']];
    }

    private function resolve(string $org,string $operation,string $id):array
    {
        return str_starts_with($operation,'creative_')?$this->creative($org,$id):$this->proposal($org,$id);
    }

    private static function json(mixed $data):string
    {
        return json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
    /** @param array<string,mixed> $data */
    private static function hash(array $data):string{return hash('sha256',self::json($data));}

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function creativeProjection(array $row):array
    {
        $row['revision']=(int)$row['revision'];
        $row['content']=json_decode((string)$row['content_json'],true,32,JSON_THROW_ON_ERROR);
        unset($row['content_json']);
        return $row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function proposalProjection(array $row):array
    {
        foreach(['revision','campaign_revision','creative_revision'] as $f)$row[$f]=(int)$row[$f];
        $row['evidence']=json_decode((string)$row['evidence_json'],true,32,JSON_THROW_ON_ERROR);
        unset($row['evidence_json']);
        $row['provider_evidence_verified']=false;
        $row['paid_activation_approved']=false;
        return $row;
    }
}

