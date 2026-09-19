<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';

use Domains\RealEstate\Automation\Event\RealEstateDomainEvents;
use Domains\RealEstate\Automation\Event\RealEstateEventType;
use Domains\RealEstate\Domain\BrokerageProcess;
use Kernel\Event\EventMetadata;
use Kernel\Shared\Domain\OrganizationId;

$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$org=OrganizationId::fromString('org-wave9');
$case=new BrokerageProcess('RE-001',$org,101,'PROP-001','INV-001','Buyer match');
$assert($case->status===BrokerageProcess::MATCHED,'Brokerage process must start as matched.');

$offered=$case->transitionTo(BrokerageProcess::OFFERED);
$assert($offered->status===BrokerageProcess::OFFERED,'Match must transition to offered.');
$viewing=$offered->transitionTo(BrokerageProcess::VIEWING);
$assert($viewing->status===BrokerageProcess::VIEWING,'Offer must transition to viewing.');
$reserved=$viewing->transitionTo(BrokerageProcess::RESERVED);
$assert($reserved->status===BrokerageProcess::RESERVED,'Viewing must transition to reserved.');

$failed=false;
try{$reserved->transitionTo(BrokerageProcess::OFFERED);}catch(InvalidArgumentException){$failed=true;}
$assert($failed,'Reserved brokerage case must be terminal for Wave 9.');

$directReservation=$case->transitionTo(BrokerageProcess::RESERVED);
$assert($directReservation->status===BrokerageProcess::RESERVED,'Direct match → reservation must be allowed.');

foreach([
    RealEstateEventType::PROPERTY_MATCHED,
    RealEstateEventType::OFFER_CREATED,
    RealEstateEventType::VIEWING_SCHEDULED,
    RealEstateEventType::PROPERTY_RESERVED,
] as $type){
    $assert(in_array($type,RealEstateEventType::values(),true),'RealEstate event vocabulary is incomplete: '.$type);
}

$event=RealEstateDomainEvents::create(
    RealEstateEventType::PROPERTY_MATCHED,
    'org-wave9',
    'RE-001',
    ['opportunity_id'=>101,'property_id'=>'PROP-001'],
    new EventMetadata('corr-wave9',null,'USER','42'),
);
$assert($event->organizationId==='org-wave9','RealEstate event lost tenant identity.');
$assert($event->aggregateType==='brokerage_case'&&$event->aggregateId==='RE-001','RealEstate event lost brokerage aggregate identity.');

$receiptPort=new ReflectionClass(Domains\RealEstate\Application\Contract\RealEstateMutationReceiptInterface::class);
$assert($receiptPort->hasMethod('claim'),'RealEstate mutation receipt contract must expose atomic claim.');

$inventoryPort=new ReflectionClass(Domains\Property\Application\Contract\PropertyInventoryCommandInterface::class);
foreach(['reserve','changeStatus'] as $method){
    $assert($inventoryPort->hasMethod($method),'Property Inventory command port missing method: '.$method);
}

echo "Property / RealEstate Wave 9 domain: OK\n";
