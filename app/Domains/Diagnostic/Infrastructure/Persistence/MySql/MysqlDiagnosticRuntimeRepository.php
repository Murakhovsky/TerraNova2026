<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Infrastructure\Persistence\MySql;

use DateTimeImmutable;
use Domains\Diagnostic\Application\Contract\DiagnosticRuntimeRepositoryInterface;
use PDO;

final readonly class MysqlDiagnosticRuntimeRepository implements DiagnosticRuntimeRepositoryInterface
{
    public function __construct(private PDO $db) {}

    public function create(string $o, string $s, string $mode, array $state, ?string $parent, DateTimeImmutable $at): void
    {
        $q=$this->db->prepare("INSERT INTO diagnostic_runtime_sessions(organization_id,session_id,parent_session_id,mode,state_json,current_question_id,state_revision,status,started_at) VALUES(:o,:s,:p,:m,CAST(:j AS JSON),NULL,0,'active',:at)");
        $q->execute(['o'=>$o,'s'=>$s,'p'=>$parent,'m'=>$mode,'j'=>$this->json($state),'at'=>$this->date($at)]);
    }

    public function get(string $o, string $s): ?array
    {
        $q=$this->db->prepare('SELECT * FROM diagnostic_runtime_sessions WHERE organization_id=:o AND session_id=:s');
        $q->execute(['o'=>$o,'s'=>$s]); $r=$q->fetch(PDO::FETCH_ASSOC); if(!$r)return null;
        $r['state']=json_decode((string)$r['state_json'],true,512,JSON_THROW_ON_ERROR); unset($r['state_json']); return $r;
    }

    public function saveState(string $o,string $s,array $state,?string $qId,int $revision):void
    {
        $q=$this->db->prepare("UPDATE diagnostic_runtime_sessions SET state_json=CAST(:j AS JSON),current_question_id=:q,state_revision=:r WHERE organization_id=:o AND session_id=:s AND status='active'");
        $q->execute(['j'=>$this->json($state),'q'=>$qId,'r'=>$revision,'o'=>$o,'s'=>$s]);
        if($q->rowCount()===0 && $this->get($o,$s)===null) throw new \DomainException('Diagnostic runtime was not found.');
    }

    public function complete(string $o,string $s,DateTimeImmutable $at):void
    {
        $q=$this->db->prepare("UPDATE diagnostic_runtime_sessions SET status='completed',current_question_id=NULL,completed_at=:at WHERE organization_id=:o AND session_id=:s");
        $q->execute(['at'=>$this->date($at),'o'=>$o,'s'=>$s]);
    }

    public function saveReport(string $o,string $s,array $report,int $rev,DateTimeImmutable $at):int
    {
        $q=$this->db->prepare('SELECT COALESCE(MAX(report_version),0)+1 FROM diagnostic_reports WHERE organization_id=:o AND session_id=:s');
        $q->execute(['o'=>$o,'s'=>$s]); $v=(int)$q->fetchColumn();
        $q=$this->db->prepare('INSERT INTO diagnostic_reports VALUES(:o,:s,:v,CAST(:j AS JSON),:r,:at)');
        $q->execute(['o'=>$o,'s'=>$s,'v'=>$v,'j'=>$this->json($report),'r'=>$rev,'at'=>$this->date($at)]); return $v;
    }

    public function report(string $o,string $s):?array
    {
        $q=$this->db->prepare('SELECT report_version,report_json,state_revision,created_at FROM diagnostic_reports WHERE organization_id=:o AND session_id=:s ORDER BY report_version DESC LIMIT 1');
        $q->execute(['o'=>$o,'s'=>$s]); $r=$q->fetch(PDO::FETCH_ASSOC); if(!$r)return null;
        $r['report']=json_decode((string)$r['report_json'],true,512,JSON_THROW_ON_ERROR); unset($r['report_json']); return $r;
    }

    public function saveRecommendation(string $o,string $s,string $id,array $payload,string $status,DateTimeImmutable $at,?string $actionId=null):void
    {
        $q=$this->db->prepare('INSERT INTO diagnostic_runtime_recommendations(organization_id,session_id,recommendation_id,payload_json,status,action_id,updated_at) VALUES(:o,:s,:id,CAST(:j AS JSON),:st,:a,:at) ON DUPLICATE KEY UPDATE payload_json=VALUES(payload_json),status=VALUES(status),action_id=COALESCE(VALUES(action_id),action_id),updated_at=VALUES(updated_at)');
        $q->execute(['o'=>$o,'s'=>$s,'id'=>$id,'j'=>$this->json($payload),'st'=>$status,'a'=>$actionId,'at'=>$this->date($at)]);
    }

    public function recommendation(string $o,string $s,string $id):?array
    {
        $q=$this->db->prepare('SELECT * FROM diagnostic_runtime_recommendations WHERE organization_id=:o AND session_id=:s AND recommendation_id=:id');
        $q->execute(['o'=>$o,'s'=>$s,'id'=>$id]); $r=$q->fetch(PDO::FETCH_ASSOC); return $r?$this->decodePayload($r):null;
    }

    public function recommendations(string $o,string $s):array
    {
        $q=$this->db->prepare('SELECT * FROM diagnostic_runtime_recommendations WHERE organization_id=:o AND session_id=:s ORDER BY updated_at DESC');
        $q->execute(['o'=>$o,'s'=>$s]); return array_map(fn(array $r)=>$this->decodePayload($r),$q->fetchAll(PDO::FETCH_ASSOC));
    }

    public function appendMeasurement(string $o,string $s,string $action,string $metric,float $value,?string $evidence,DateTimeImmutable $at):void
    {
        $id=substr(hash('sha256',$o.':'.$s.':'.$action.':'.$metric.':'.$at->format('U.u')),0,64);
        $q=$this->db->prepare('INSERT INTO diagnostic_measurements VALUES(:o,:id,:s,:a,:m,:v,:e,:at)');
        $q->execute(['o'=>$o,'id'=>$id,'s'=>$s,'a'=>$action,'m'=>$metric,'v'=>$value,'e'=>$evidence,'at'=>$this->date($at)]);
    }

    public function measurements(string $o,string $s):array
    {
        $q=$this->db->prepare('SELECT action_id,metric_code,metric_value,evidence_id,measured_at FROM diagnostic_measurements WHERE organization_id=:o AND session_id=:s ORDER BY measured_at');
        $q->execute(['o'=>$o,'s'=>$s]); return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function schedule(string $o,string $source,int $days,DateTimeImmutable $due,DateTimeImmutable $at):array
    {
        $id=substr(hash('sha256',$o.':'.$source.':'.$days),0,64);
        $q=$this->db->prepare("INSERT INTO diagnostic_rediagnostic_schedules(organization_id,schedule_id,source_session_id,interval_days,due_at,status,created_at) VALUES(:o,:id,:s,:d,:due,'pending',:at) ON DUPLICATE KEY UPDATE due_at=VALUES(due_at)");
        $q->execute(['o'=>$o,'id'=>$id,'s'=>$source,'d'=>$days,'due'=>$this->date($due),'at'=>$this->date($at)]);
        return ['schedule_id'=>$id,'source_session_id'=>$source,'interval_days'=>$days,'due_at'=>$due->format(DATE_ATOM),'status'=>'pending'];
    }

    public function dueSchedules(string $o,DateTimeImmutable $at,int $limit=100):array
    {
        $q=$this->db->prepare("SELECT * FROM diagnostic_rediagnostic_schedules WHERE organization_id=:o AND status='pending' AND due_at<=:at ORDER BY due_at LIMIT ".max(1,min(500,$limit)));
        $q->execute(['o'=>$o,'at'=>$this->date($at)]); return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function pendingScheduleForSource(string $o,string $source):?array
    {
        $q=$this->db->prepare("SELECT * FROM diagnostic_rediagnostic_schedules WHERE organization_id=:o AND source_session_id=:s AND status='pending' ORDER BY due_at LIMIT 1");
        $q->execute(['o'=>$o,'s'=>$source]); $r=$q->fetch(PDO::FETCH_ASSOC); return $r?:null;
    }

    public function markScheduleStarted(string $o,string $id,string $followup,DateTimeImmutable $at):void
    {
        $q=$this->db->prepare("UPDATE diagnostic_rediagnostic_schedules SET status='started',followup_session_id=:f,started_at=:at WHERE organization_id=:o AND schedule_id=:id AND status='pending'");
        $q->execute(['f'=>$followup,'at'=>$this->date($at),'o'=>$o,'id'=>$id]);
        if($q->rowCount()!==1) throw new \DomainException('Re-diagnostic schedule is not pending.');
    }

    private function decodePayload(array $r):array{$r['payload']=json_decode((string)$r['payload_json'],true,512,JSON_THROW_ON_ERROR);unset($r['payload_json']);return $r;}
    private function json(array $v):string{return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);}
    private function date(DateTimeImmutable $v):string{return $v->format('Y-m-d H:i:s.u');}
}
