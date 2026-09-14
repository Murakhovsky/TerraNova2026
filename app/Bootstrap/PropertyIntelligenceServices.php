<?php
declare(strict_types=1);

use Domains\Property\Application\Service\PropertyComparableSelector;
use Domains\Property\Application\Service\PropertyIntelligenceService;
use Domains\Property\Infrastructure\AI\StructuredLlmPropertyIntelligenceProvider;
use Domains\Property\Infrastructure\Persistence\MySql\MysqlPropertyIntelligenceRepository;
use Infrastructure\Platform\Analytics\PropertyIntelligenceCoordinator;

$di->setShared('propertyIntelligenceRepository', fn (): MysqlPropertyIntelligenceRepository => new MysqlPropertyIntelligenceRepository(
    $this->getShared('databaseService')->connection(),
));
$di->setShared('propertyIntelligenceProvider', fn (): StructuredLlmPropertyIntelligenceProvider => new StructuredLlmPropertyIntelligenceProvider(
    $this->getShared('cosLlmClient'),
));
$di->setShared('propertyIntelligenceService', fn (): PropertyIntelligenceService => new PropertyIntelligenceService(
    $this->getShared('propertyIntelligenceProvider'),
    $this->getShared('propertyIntelligenceRepository'),
));
$di->setShared('propertyComparableSelector', fn (): PropertyComparableSelector => new PropertyComparableSelector());
$di->setShared('propertyIntelligenceCoordinator', fn (): PropertyIntelligenceCoordinator => new PropertyIntelligenceCoordinator(
    $this->getShared('propertyReferencePort'),
    $this->getShared('propertyAnalyticsReadModel'),
    $this->getShared('propertyMarketAnalytics'),
    $this->getShared('propertyComparableSelector'),
    $this->getShared('propertyIntelligenceService'),
    $this->getShared('organizationContext')->id(),
));
