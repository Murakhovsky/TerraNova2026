<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Automation\Handler;
use DateTimeImmutable;
use Domains\Diagnostic\Application\UseCase\{CaptureDiagnosticEvidence,RecordDiagnosticResult};
use Domains\Diagnostic\Model\{DiagnosticRecord,DiagnosticRecordType,Evidence,EvidenceType};
use Kernel\Action\Event\ActionExecutionFinished;
use Kernel\Event\Contract\EventHandlerInterface;
use Kernel\Event\DomainEvent;
final readonly class DiagnosticActionOutcomeHandler implements EventHandlerInterface
{
 public function __construct(private CaptureDiagnosticEvidence $capture,private RecordDiagnosticResult $record){}
 public function handle(DomainEvent $event):void{if($event->type!==ActionExecutionFinished::COMPLETED||($event->payload['action_type']??'')!=='IMPLEMENT_DIAGNOSTIC_RECOMMENDATION')return;$sessionId=$event->metadata->correlationId;$at=$event->occurredAt;$evidenceId='action-result-'.$event->payload['action_id'];$this->capture->execute($event->organizationId,$sessionId,new Evidence($evidenceId,EvidenceType::SystemData,'Measured recommendation outcome','cos-action:'.$event->payload['action_id'],$at,['result_status'=>$event->payload['status']??''],null,'COS_ACTION_RESULT',1,1,null,null,$event->payload['output']??[]),'SYSTEM','diagnostic-action-outcome');foreach($event->payload['metrics']??[] as $metric=>$value)if(is_numeric($value))$this->record->execute($event->organizationId,$sessionId,new DiagnosticRecord('measurement-'.$event->payload['action_id'].'-'.$metric,DiagnosticRecordType::Metric,(string)$metric,'Measured after recommendation implementation',(float)$value,null,[$evidenceId],[],$at),'SYSTEM','diagnostic-action-outcome');}
}
