<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use DateTimeImmutable;
use Domains\Service\Application\Contract\ServiceApplicationBoundary;
use Domains\Service\Automation\Event\ServiceEventType;
use Domains\Service\Bootstrap\ServiceDomainModule;
use Domains\Service\Domain\ServiceTicketLifecycle;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventMetadata;

function expectService(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

expectService(ServiceTicketLifecycle::assignmentStatus(ServiceTicketLifecycle::OPEN)===ServiceTicketLifecycle::ASSIGNED,'Open ticket assignment must enter assigned.');
expectService(ServiceTicketLifecycle::assignmentStatus(ServiceTicketLifecycle::ESCALATED)===ServiceTicketLifecycle::ESCALATED,'Reassigning escalated ticket must preserve escalated state.');
ServiceTicketLifecycle::assertMutable(ServiceTicketLifecycle::OPEN,'set SLA');
ServiceTicketLifecycle::assertMutable(ServiceTicketLifecycle::ASSIGNED,'be escalated');
ServiceTicketLifecycle::assertResolvable(ServiceTicketLifecycle::ESCALATED);
ServiceTicketLifecycle::assertClosable(ServiceTicketLifecycle::RESOLVED);

foreach([
    [fn()=>ServiceTicketLifecycle::assertMutable(ServiceTicketLifecycle::RESOLVED,'be assigned'),'resolved mutation'],
    [fn()=>ServiceTicketLifecycle::assertClosable(ServiceTicketLifecycle::OPEN),'close before resolve'],
    [fn()=>ServiceTicketLifecycle::assertKnown('mystery'),'unknown status'],
] as [$call,$label]){
    try{
        $call();
        throw new RuntimeException('Expected Service lifecycle rejection: '.$label);
    }catch(InvalidArgumentException){}
}

expectService(count(ServiceEventType::values())===7,'Service must expose seven canonical lifecycle events.');
expectService(count(array_unique(ServiceEventType::values()))===7,'Service event types must be unique.');

$module=new ServiceDomainModule();
expectService($module->name()==='service','Service runtime module name mismatch.');
foreach(ServiceEventType::values() as $type){
    expectService($module->eventTypes()!==[]&&in_array($type,$module->eventTypes(),true),'Service module must own event '.$type);
}

$event=new DomainEvent(
    'evt-service-1','org-1',ServiceEventType::TICKET_ESCALATED,'service_ticket','STKT-1',
    ['level'=>2,'reason'=>'SLA risk'],
    new EventMetadata('corr-1',null,'USER','1'),
    new DateTimeImmutable('2026-09-19T10:00:00+00:00'),
);
$context=$module->ruleContextProvider()->contextFor($event);
expectService(($context['organization_id']??null)==='org-1','Service rule context must preserve tenant.');
expectService(($context['service']['level']??null)===2,'Service rule context must expose event payload.');

$boundary=new ReflectionClass(ServiceApplicationBoundary::class);
foreach(['createRequest','createTicket','assignTicket','setSla','escalate','resolve','close','viewRequest','viewTicket'] as $method){
    expectService($boundary->hasMethod($method),'Service application boundary missing '.$method.'.');
}

echo "Service Wave 11 runtime contracts passed.\n";
