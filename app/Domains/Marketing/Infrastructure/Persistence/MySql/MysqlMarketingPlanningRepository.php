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

    public function saveAudience(string $org,string $actor,?string $id,?int $expectedRevision,array $content,string $key):array
    {
        $hash=self::hash([$actor,$id,$expectedRevision,$content]);
        return $this->write($org,'audience_save',$key,$hash,function()use($org,$actor,$id,$expectedRevision,$content):array{
            $json=self::json($content);
            if($id===null){
                $id='mkau-'.bin2hex(random_bytes(12));$revision=1;
                $this->connection->prepare("INSERT INTO marketing_audiences (organization_id,id,name,target_spec_json,status,revision)
                    VALUES (?,?,?,?,'DRAFT',1)")->execute([$org,$id,$content['name'],self::json($content['target_spec'])]);
            }else{
                $q=$this->connection->prepare("UPDATE marketing_audiences SET name=?,target_spec_json=?,revision=revision+1
                    WHERE organization_id=? AND id=? AND revision=? AND status='DRAFT'");
                $q->execute([$content['name'],self::json($content['target_spec']),$org,$id,$expectedRevision]);
                if($q->rowCount()!==1)throw new DomainException('Audience unavailable or revision stale.');
                $revision=(int)$expectedRevision+1;
            }
            $this->connection->prepare('INSERT INTO marketing_audience_revisions
                (organization_id,audience_id,revision,actor_id,content_hash,content_json) VALUES (?,?,?,?,?,?)')
                ->execute([$org,$id,$revision,$actor,hash('sha256',$json),$json]);
            return $this->planRevision($org,'audience',$id,$revision);
        });
    }

    public function audiences(string $org):array
    {
        $q=$this->connection->prepare("SELECT a.id,a.revision,r.actor_id,r.content_hash,r.content_json
            FROM marketing_audiences a JOIN marketing_audience_revisions r ON r.organization_id=a.organization_id
            AND r.audience_id=a.id AND r.revision=a.revision WHERE a.organization_id=? AND a.status='DRAFT'
            ORDER BY a.updated_at DESC,a.id DESC LIMIT 100");
        $q->execute([$org]);
        return array_map(self::planProjection(...),$q->fetchAll(PDO::FETCH_ASSOC));
    }

    public function saveAdset(string $org,string $campaignId,int $campaignRevision,string $actor,?string $id,?int $expectedRevision,array $content,string $key):array
    {
        $hash=self::hash([$campaignId,$campaignRevision,$actor,$id,$expectedRevision,$content]);
        return $this->write($org,'adset_save',$key,$hash,function()use($org,$campaignId,$campaignRevision,$actor,$id,$expectedRevision,$content):array{
            $campaign=$this->campaign($org,$campaignId,true);
            if($campaign['status']!=='DRAFT'||(int)$campaign['revision']!==$campaignRevision)throw new DomainException('Campaign revision stale.');
            $q=$this->connection->prepare("SELECT a.revision FROM marketing_audiences a JOIN marketing_audience_revisions r
                ON r.organization_id=a.organization_id AND r.audience_id=a.id AND r.revision=a.revision
                WHERE a.organization_id=? AND a.id=? AND a.status='DRAFT' FOR UPDATE");
            $q->execute([$org,$content['audience_id']]);$current=$q->fetchColumn();
            if($current===false||(int)$current!==$content['audience_revision'])throw new DomainException('Audience pin stale or foreign.');
            foreach($content['creatives'] as $pin){
                $creative=$this->lockedCreative($org,$pin['creative_id']);
                if($creative['campaign_id']!==$campaignId||$creative['status']!=='DRAFT'||(int)$creative['revision']!==$pin['revision']){
                    throw new DomainException('Creative pin stale or foreign to Campaign.');
                }
            }
            if($id===null){
                $id='mkas-'.bin2hex(random_bytes(12));$revision=1;
                $this->connection->prepare("INSERT INTO marketing_adset_drafts (organization_id,id,campaign_id,revision,status)
                    VALUES (?,?,?,1,'DRAFT')")->execute([$org,$id,$campaignId]);
            }else{
                $q=$this->connection->prepare("UPDATE marketing_adset_drafts SET revision=revision+1 WHERE organization_id=?
                    AND id=? AND campaign_id=? AND revision=? AND status='DRAFT'");
                $q->execute([$org,$id,$campaignId,$expectedRevision]);
                if($q->rowCount()!==1)throw new DomainException('AdSet unavailable or revision stale.');
                $revision=(int)$expectedRevision+1;
            }
            $json=self::json(['campaign_id'=>$campaignId,'campaign_revision'=>$campaignRevision,...$content]);
            $this->connection->prepare('INSERT INTO marketing_adset_revisions
                (organization_id,adset_id,revision,actor_id,content_hash,content_json) VALUES (?,?,?,?,?,?)')
                ->execute([$org,$id,$revision,$actor,hash('sha256',$json),$json]);
            return $this->planRevision($org,'adset',$id,$revision);
        });
    }

    public function adsets(string $org,string $campaignId):array
    {
        $campaign=$this->campaign($org,$campaignId,false);
        $q=$this->connection->prepare("SELECT a.id,a.revision,r.actor_id,r.content_hash,r.content_json
            FROM marketing_adset_drafts a JOIN marketing_adset_revisions r ON r.organization_id=a.organization_id
            AND r.adset_id=a.id AND r.revision=a.revision WHERE a.organization_id=? AND a.campaign_id=? AND a.status='DRAFT'
            ORDER BY a.updated_at DESC,a.id DESC LIMIT 100");
        $q->execute([$org,$campaignId]);
        $items=array_map(self::planProjection(...),$q->fetchAll(PDO::FETCH_ASSOC));
        foreach($items as &$item){
            $content=$item['content'];$stale=$campaign['status']!=='DRAFT'||(int)$campaign['revision']!==$content['campaign_revision'];
            $a=$this->connection->prepare("SELECT revision FROM marketing_audiences WHERE organization_id=? AND id=? AND status='DRAFT'");
            $a->execute([$org,$content['audience_id']]);$current=$a->fetchColumn();
            $stale=$stale||$current===false||(int)$current!==$content['audience_revision'];
            $c=$this->connection->prepare("SELECT revision FROM marketing_creative_briefs WHERE organization_id=? AND id=? AND campaign_id=? AND status='DRAFT'");
            foreach($content['creatives'] as $pin){
                $c->execute([$org,$pin['creative_id'],$campaignId]);$current=$c->fetchColumn();
                $stale=$stale||$current===false||(int)$current!==$pin['revision'];
            }
            $item['pins_stale']=$stale;
        }
        unset($item);
        return $items;
    }

    /** Resolve immutable receipt revision, not mutable current state. */
    private function planRevision(string $org,string $kind,string $id,int $revision):array
    {
        $sql=$kind==='audience'
            ?'SELECT audience_id AS id,revision,actor_id,content_hash,content_json FROM marketing_audience_revisions WHERE organization_id=? AND audience_id=? AND revision=?'
            :'SELECT adset_id AS id,revision,actor_id,content_hash,content_json FROM marketing_adset_revisions WHERE organization_id=? AND adset_id=? AND revision=?';
        $q=$this->connection->prepare($sql);$q->execute([$org,$id,$revision]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))throw new DomainException('Planning revision receipt missing.');
        return self::planProjection($row);
    }

    private static function planProjection(array $row):array
    {
        $row['revision']=(int)$row['revision'];
        $row['content']=json_decode((string)$row['content_json'],true,32,JSON_THROW_ON_ERROR);
        unset($row['content_json']);$row['status']='DRAFT';$row['launch_eligible']=false;
        return $row;
    }

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
            // Resolve parent without a lock, then enforce stable Campaign -> Creative lock ordering.
            $q=$this->connection->prepare('SELECT campaign_id FROM marketing_creative_briefs WHERE organization_id=? AND id=?');
            $q->execute([$org,$creativeId]);$parent=$q->fetchColumn();
            if(!is_string($parent)||$parent==='')throw new DomainException('Marketing Creative unavailable.');
            $campaign=$this->campaign($org,$parent,true);
            $brief=$this->lockedCreative($org,$creativeId);
            if($brief['campaign_id']!==$parent)throw new DomainException('Creative parent changed during revision.');
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
                $result=$this->resolve($org,$op,$existing['id'],$existing['revision']);
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
                if($existing!==null)return $this->resolve($org,$op,$existing['id'],$existing['revision']);
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

    private function resolve(string $org,string $operation,string $id,int $revision):array
    {
        if($operation==='audience_save'||$operation==='adset_save')return $this->planRevision($org,$operation==='audience_save'?'audience':'adset',$id,$revision);
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

