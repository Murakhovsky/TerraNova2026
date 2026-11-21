<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';

use Domains\ProductManagement\Bootstrap\ProductManagementDomainModule;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventMetadata;
use Kernel\Module\DomainModuleRegistry;

$module=new ProductManagementDomainModule();
$registry=new DomainModuleRegistry([$module]);
$event=new DomainEvent(
    bin2hex(random_bytes(16)),'ci-tenant','product_management.product.created','Product',
    'product-1',['name'=>'A'],new EventMetadata('ci-correlation',null,'USER','ci'),
    new DateTimeImmutable('now',new DateTimeZone('UTC')),
);
$ctx=$module->ruleContextProvider()->contextFor($event);
if ($module->name()!=='product_management'||$ctx['event_type']!==$event->type
    ||$ctx['organization_id']!==$event->organizationId) {
    throw new RuntimeException('Product Management EventOwnership/Rule context is invalid.');
}
try {
    $wrong=new DomainEvent('other-event','ci-tenant','sales.deal.created','Deal',
        'deal-1',[],new EventMetadata('ci-correlation',null,'USER','ci'),
        new DateTimeImmutable('now',new DateTimeZone('UTC')));
    $module->ruleContextProvider()->contextFor($wrong);
    throw new RuntimeException('Cross-domain event accepted.');
} catch (InvalidArgumentException) {}
echo "Product Management Domain event registration passed.\n";
