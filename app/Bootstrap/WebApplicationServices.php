<?php
declare(strict_types=1);

namespace Bootstrap;

use Domains\Sales\Infrastructure\Persistence\MySql\MysqlPresentationSales;
use Domains\Identity\Infrastructure\ReadModel\MySql\AdminDashboardService;
use Infrastructure\Platform\Analytics\MysqlPropertyFunnelAnalytics;
use Infrastructure\Platform\Analytics\MysqlPropertyAnalytics;
use Domains\Property\Infrastructure\ReadModel\MySql\CatalogService;
use Interfaces\Web\Service\ClientCaseService;
use Domains\Content\Application\Service\ContentService;
use Domains\Content\Infrastructure\Persistence\MySql\MysqlContentRepository;
use Interfaces\Web\Service\InboundRequestService;
use Infrastructure\Integration\N8n\N8nWebhookService;
use Domains\Property\Application\Service\PropertyManagementService;
use Domains\Property\Infrastructure\Persistence\MySql\MysqlPropertyManagementRepository;
use Domains\Property\Application\UseCase\PropertyModerationService;
use Domains\Property\Infrastructure\Persistence\MySql\MysqlPropertyModerationRepository;
use Domains\Property\Infrastructure\Presentation\PropertyPresentationService;
use Domains\Property\Application\UseCase\PropertySubmissionService;
use Domains\Property\Infrastructure\Persistence\MySql\MysqlPropertySubmissionRepository;
use Infrastructure\Platform\Persistence\MySql\MysqlLocationReference;
use Infrastructure\Platform\Persistence\MySql\MysqlContentIntegrationOutbox;
use Interfaces\Web\Page\PublicPageService;
use Phalcon\Di\DiInterface;

final class WebApplicationServices
{
    public static function register(DiInterface $di): void
    {
        $di->setShared('frontendAdminDashboardService', fn() => new AdminDashboardService($di->getShared('databaseService')));
        $di->setShared('frontendAnalyticsService', fn() => new MysqlPropertyFunnelAnalytics($di->getShared('databaseService')));
        $di->setShared('frontendPublicPageService', fn() => new PublicPageService());
        $di->setShared('contentRepository', fn() => new MysqlContentRepository(
            $di->getShared('databaseService'), new MysqlContentIntegrationOutbox($di->getShared('databaseService')),
        ));
        $di->setShared('frontendContentService', fn() => new ContentService($di->getShared('contentRepository')));
        $di->setShared('propertyAnalytics', fn() => new MysqlPropertyAnalytics($di->getShared('databaseService')));
        $di->setShared('frontendCatalogService', fn() => new CatalogService($di->getShared('databaseService'), $di->getShared('propertyAnalytics')));
        $di->setShared('frontendClientCaseService', fn() => new ClientCaseService(
            $di->getShared('salesClientCaseReadModel'), $di->getShared('salesClientCaseService'), $di->getShared('salesInboundService'),
        ));
        $di->setShared('frontendInboundRequestService', fn() => new InboundRequestService($di->getShared('salesReceivePublicLead')));

        $di->setShared('propertySubmissionRepository', fn() => new MysqlPropertySubmissionRepository($di->getShared('databaseService')));
        $di->setShared('frontendPropertySubmissionService', fn() => new PropertySubmissionService(
            $di->getShared('propertySubmissionRepository'), $di->getShared('mediaStorageService'), null, $di->getShared('propertyAnalytics'),
        ));
        $di->setShared('propertyModerationRepository', fn() => new MysqlPropertyModerationRepository(
            $di->getShared('databaseService'), $di->getShared('mediaStorageService'), new MysqlLocationReference($di->getShared('databaseService')),
        ));
        $di->setShared('frontendPropertyModerationService', fn() => new PropertyModerationService($di->getShared('propertyModerationRepository')));
        $di->setShared('propertyManagementRepository', fn() => new MysqlPropertyManagementRepository(
            $di->getShared('databaseService'), $di->getShared('mediaStorageService'), $di->getShared('organizationContext')->id(),
        ));
        $di->setShared('frontendPropertyMediaService', fn() => new PropertyManagementService($di->getShared('propertyManagementRepository')));
        $di->setShared('frontendPropertyPresentationService', fn() => new PropertyPresentationService(
            $di->getShared('frontendCatalogService'), $di->getShared('databaseService'), null,
            $di->getShared('propertyAnalytics'), new MysqlPresentationSales($di->getShared('databaseService')),
        ));
        $di->setShared('frontendN8nWebhookService', fn() => new N8nWebhookService(
            $di->getShared('databaseService'), $di->getShared('frontendContentService'),
            (string) $di->getShared('config')->integrations->n8n->inbound_secret,
            (int) $di->getShared('config')->integrations->n8n->max_clock_skew,
        ));
    }
}
