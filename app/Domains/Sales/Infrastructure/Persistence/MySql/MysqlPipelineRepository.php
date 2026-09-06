<?php
declare(strict_types=1);
namespace Domains\Sales\Infrastructure\Persistence\MySql;
use Domains\Sales\Application\Contract\PipelineRepositoryInterface;
use Domains\Sales\Model\{PipelineDefinition,PipelineStageDefinition,PipelineTransitionDefinition};
use PDO;
final readonly class MysqlPipelineRepository implements PipelineRepositoryInterface
{
    public function __construct(private PDO $connection){}
    public function getPipeline(string $organizationId,string $pipelineId):?PipelineDefinition{$row=$this->one('SELECT id,organization_id,code,name FROM sales_pipelines WHERE id=:id AND organization_id=:org AND status="ACTIVE"',['id'=>$pipelineId,'org'=>$organizationId]);return $row?$this->pipeline($row):null;}
    public function getDefaultPipeline(string $organizationId):?PipelineDefinition{$row=$this->one('SELECT id,organization_id,code,name FROM sales_pipelines WHERE organization_id=:org AND status="ACTIVE" ORDER BY is_default DESC,created_at,id LIMIT 1',['org'=>$organizationId]);return $row?$this->pipeline($row):null;}
    public function getStage(string $organizationId,string $stageId):?PipelineStageDefinition{$row=$this->one('SELECT s.* FROM sales_pipeline_stages s INNER JOIN sales_pipelines p ON p.id=s.pipeline_id AND p.organization_id=:org AND p.status="ACTIVE" WHERE s.id=:id',['id'=>$stageId,'org'=>$organizationId]);return $row?$this->stage($row):null;}
    public function findStageByCode(string $organizationId,string $pipelineId,string $stageCode):?PipelineStageDefinition{$row=$this->one('SELECT s.* FROM sales_pipeline_stages s INNER JOIN sales_pipelines p ON p.id=s.pipeline_id AND p.organization_id=:org AND p.status="ACTIVE" WHERE s.pipeline_id=:pipeline AND s.code=:code',['org'=>$organizationId,'pipeline'=>$pipelineId,'code'=>strtoupper($stageCode)]);return $row?$this->stage($row):null;}
    public function getTransition(string $organizationId,string $pipelineId,string $fromStageId,string $toStageId):?PipelineTransitionDefinition{$row=$this->one('SELECT t.* FROM sales_pipeline_transitions t INNER JOIN sales_pipelines p ON p.id=t.pipeline_id AND p.organization_id=:org AND p.status="ACTIVE" WHERE t.pipeline_id=:pipeline AND t.from_stage_id=:from_stage AND t.to_stage_id=:to_stage',['org'=>$organizationId,'pipeline'=>$pipelineId,'from_stage'=>$fromStageId,'to_stage'=>$toStageId]);return $row?new PipelineTransitionDefinition((string)$row['id'],(string)$row['pipeline_id'],(string)$row['from_stage_id'],(string)$row['to_stage_id'],json_decode((string)($row['conditions']??'[]'),true)?:[],(bool)$row['requires_approval']):null;}
    private function pipeline(array $row):PipelineDefinition{$st=$this->connection->prepare('SELECT * FROM sales_pipeline_stages WHERE pipeline_id=:id ORDER BY sort_order,id');$st->execute(['id'=>$row['id']]);$stages=array_map(fn($r)=>$this->stage($r),$st->fetchAll(PDO::FETCH_ASSOC));return new PipelineDefinition((string)$row['id'],(string)$row['organization_id'],(string)$row['code'],(string)$row['name'],$stages);}
    private function stage(array $r):PipelineStageDefinition{return new PipelineStageDefinition((string)$r['id'],(string)$r['code'],(string)$r['name'],(int)$r['sort_order'],(bool)$r['is_terminal'],(bool)$r['is_won'],(bool)$r['is_lost'],(float)$r['probability_default']);}
    private function one(string $sql,array $params):?array{$s=$this->connection->prepare($sql);$s->execute($params);$r=$s->fetch(PDO::FETCH_ASSOC);return is_array($r)?$r:null;}
}
