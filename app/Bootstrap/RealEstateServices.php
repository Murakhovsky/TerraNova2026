<?php
declare(strict_types=1);

use Domains\Property\Application\Service\CanonicalPropertyInventoryCommands;
use Domains\RealEstate\Application\Service\RealEstateWorkflowService;
use Domains\RealEstate\Bootstrap\RealEstateDomainModule;
use Domains\RealEstate\Infrastructure\Persistence\MySql\MysqlRealEstateRepository;
use Domains\RealEstate\Infrastructure\Persistence\MySql\MysqlRealEstateMutationReceipt;
use Domains\RealEstate\Infrastructure\Sales\SalesOpportunityReferenceAdapter;

$di->setShared('realEstateRepository', fn (): MysqlRealEstateRepository => new MysqlRealEstateRepository(
    $this->getShared('databaseService')->connection(),
));
$di->setShared('realEstateMutationReceipt', fn (): MysqlRealEstateMutationReceipt => new MysqlRealEstateMutationReceipt(
    $this->getShared('databaseService')->connection(),
));
$di->setShared('realEstateSalesReference', fn (): SalesOpportunityReferenceAdapter => new SalesOpportunityReferenceAdapter(
    $this->getShared('salesWorkspaceReadModel'),
));
$di->setShared('propertyInventoryCommands', fn (): CanonicalPropertyInventoryCommands => new CanonicalPropertyInventoryCommands(
    $this->getShared('propertyCanonicalRuntime'),
));
$di->setShared('realEstateWorkflow', fn (): RealEstateWorkflowService => new RealEstateWorkflowService(
    $this->getShared('realEstateRepository'),
    $this->getShared('realEstateMutationReceipt'),
    $this->getShared('realEstateSalesReference'),
    $this->getShared('propertyReferencePort'),
    $this->getShared('propertyInventoryCommands'),
    $this->getShared('eventBus'),
    $this->getShared('cosTransactionManager'),
    $this->getShared('cosAuditRepository'),
));
$di->setShared('realEstateDomainModule', fn (): RealEstateDomainModule => new RealEstateDomainModule());
