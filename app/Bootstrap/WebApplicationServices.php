<?php
declare(strict_types=1);

namespace Bootstrap;

use Domains\Sales\Application\UseCase\ReceivePublicLead;
use Domains\Sales\Application\UseCase\AddClientCaseActivity;
use Domains\Sales\Application\UseCase\AddClientCasePropertyMatch;
use Domains\Sales\Application\UseCase\AttachInboundRequest;
use Domains\Sales\Application\UseCase\CreateClientCase;
use Domains\Sales\Application\UseCase\CreateClientCaseFromInboundRequest;
use Domains\Sales\Application\UseCase\EnsureInboundClientCase;
use Domains\Sales\Application\UseCase\QuickUpdateClientCase;
use Domains\Sales\Application\UseCase\RegisterInboundClientCaseRequest;
use Domains\Sales\Application\UseCase\ResolveInboundProperty;
use Domains\Sales\Application\UseCase\UpdateClientCase;
use Domains\Sales\Application\UseCase\UpdateClientCasePropertyMatch;
use Domains\Sales\Application\UseCase\UpdateInboundClientCaseRequest;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlInboundLeadRepository;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlClientCaseCommandRepository;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlPresentationSales;
use Domains\Sales\Infrastructure\ReadModel\MySql\MysqlClientCaseReadModel;
use Domains\Identity\Infrastructure\ReadModel\MySql\AdminDashboardService;
use Domains\Analytics\Infrastructure\ReadModel\MySql\AnalyticsService;
use Domains\Analytics\Infrastructure\Persistence\MySql\MysqlPropertyAnalytics;
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
        $di->setShared('frontendAnalyticsService', fn() => new AnalyticsService($di->getShared('databaseService')));
        $di->setShared('frontendPublicPageService', fn() => new PublicPageService());
        $di->setShared('contentRepository', fn() => new MysqlContentRepository(
            $di->getShared('databaseService'), new MysqlContentIntegrationOutbox($di->getShared('databaseService')),
        ));
        $di->setShared('frontendContentService', fn() => new ContentService($di->getShared('contentRepository')));
        $di->setShared('propertyAnalytics', fn() => new MysqlPropertyAnalytics($di->getShared('databaseService')));
        $di->setShared('frontendCatalogService', fn() => new CatalogService(
            $di->getShared('databaseService'), $di->getShared('propertyAnalytics'),
        ));
        $di->setShared('salesClientCaseReadModel', fn() => new MysqlClientCaseReadModel(
            $di->getShared('databaseService')->connection(), $di->getShared('organizationContext')->id(),
        ));
        $di->setShared('salesClientCaseCommands', fn() => new MysqlClientCaseCommandRepository(
            $di->getShared('databaseService')->connection(),
        ));
        $di->setShared('salesCreateClientCase', fn() => new CreateClientCase(
            $di->getShared('salesClientCaseCommands'), $di->getShared('eventBus'),
            $di->getShared('cosTransactionManager'), $di->getShared('organizationContext')->id(),
        ));
        $di->setShared('salesUpdateClientCase', fn() => new UpdateClientCase(
            $di->getShared('salesClientCaseReadModel'), $di->getShared('salesClientCaseCommands'),
            $di->getShared('eventBus'), $di->getShared('cosTransactionManager'), $di->getShared('organizationContext')->id(),
        ));
        $di->setShared('salesQuickUpdateClientCase', fn() => new QuickUpdateClientCase(
            $di->getShared('salesClientCaseReadModel'), $di->getShared('salesClientCaseCommands'),
            $di->getShared('eventBus'), $di->getShared('cosTransactionManager'), $di->getShared('organizationContext')->id(),
        ));
        $di->setShared('salesAddClientCaseActivity', fn() => new AddClientCaseActivity(
            $di->getShared('salesClientCaseReadModel'), $di->getShared('salesClientCaseCommands'),
            $di->getShared('salesCompleteCall'), $di->getShared('cosTransactionManager'), $di->getShared('organizationContext')->id(),
        ));
        $di->setShared('salesAttachInboundRequest', fn() => new AttachInboundRequest(
            $di->getShared('salesClientCaseReadModel'), $di->getShared('salesClientCaseCommands'),
            $di->getShared('eventBus'), $di->getShared('cosTransactionManager'), $di->getShared('organizationContext')->id(),
        ));
        $di->setShared('salesUpdateInboundClientCaseRequest', fn() => new UpdateInboundClientCaseRequest(
            $di->getShared('salesClientCaseReadModel'), $di->getShared('salesClientCaseCommands'),
            $di->getShared('eventBus'), $di->getShared('cosTransactionManager'), $di->getShared('organizationContext')->id(),
        ));
        $di->setShared('salesCreateClientCaseFromInboundRequest', fn() => new CreateClientCaseFromInboundRequest(
            $di->getShared('salesClientCaseCommands'), $di->getShared('eventBus'),
            $di->getShared('cosTransactionManager'), $di->getShared('organizationContext')->id(),
        ));
        $di->setShared('salesAddClientCasePropertyMatch', fn() => new AddClientCasePropertyMatch(
            $di->getShared('salesClientCaseReadModel'), $di->getShared('salesClientCaseCommands'),
            $di->getShared('cosTransactionManager'), $di->getShared('organizationContext')->id(),
        ));
        $di->setShared('salesUpdateClientCasePropertyMatch', fn() => new UpdateClientCasePropertyMatch(
            $di->getShared('salesClientCaseReadModel'), $di->getShared('salesClientCaseCommands'),
            $di->getShared('cosTransactionManager'), $di->getShared('organizationContext')->id(),
        ));
        $di->setShared('salesEnsureInboundClientCase', fn() => new EnsureInboundClientCase(
            $di->getShared('salesClientCaseCommands'), $di->getShared('eventBus'),
            $di->getShared('cosTransactionManager'), $di->getShared('organizationContext')->id(),
        ));
        $di->setShared('salesRegisterInboundClientCaseRequest', fn() => new RegisterInboundClientCaseRequest(
            $di->getShared('salesClientCaseCommands'), $di->getShared('cosTransactionManager'),
            $di->getShared('organizationContext')->id(),
        ));
        $di->setShared('salesResolveInboundProperty', fn() => new ResolveInboundProperty(
            $di->getShared('salesClientCaseCommands'),
        ));
        $di->setShared('frontendClientCaseService', fn() => new ClientCaseService(
            $di->getShared('salesClientCaseReadModel'), $di->getShared('salesCreateClientCase'),
            $di->getShared('salesUpdateClientCase'), $di->getShared('salesQuickUpdateClientCase'),
            $di->getShared('salesAddClientCaseActivity'), $di->getShared('salesAttachInboundRequest'),
            $di->getShared('salesUpdateInboundClientCaseRequest'), $di->getShared('salesCreateClientCaseFromInboundRequest'),
            $di->getShared('salesAddClientCasePropertyMatch'), $di->getShared('salesUpdateClientCasePropertyMatch'),
            $di->getShared('salesRegisterInboundClientCaseRequest'), $di->getShared('salesResolveInboundProperty'),
            $di->getShared('salesEnsureInboundClientCase'),
        ));
        $di->setShared('salesInboundLeadRepository', fn() => new MysqlInboundLeadRepository(
            $di->getShared('databaseService')->connection(),
        ));
        $di->setShared('salesInboundCaseResolver', fn() => new InboundCaseResolverAdapter(
            $di->getShared('salesResolveInboundProperty'), $di->getShared('salesEnsureInboundClientCase'),
            $di->getShared('salesRegisterInboundClientCaseRequest'),
        ));
        $di->setShared('salesReceivePublicLead', fn() => new ReceivePublicLead(
            $di->getShared('salesInboundLeadRepository'), $di->getShared('salesInboundCaseResolver'),
            $di->getShared('eventBus'), $di->getShared('cosTransactionManager'),
            $di->getShared('organizationContext')->id(),
        ));
        $di->setShared('frontendInboundRequestService', fn() => new InboundRequestService(
            $di->getShared('salesReceivePublicLead'),
        ));
        $di->setShared('propertySubmissionRepository', fn() => new MysqlPropertySubmissionRepository(
            $di->getShared('databaseService'),
        ));
        $di->setShared('frontendPropertySubmissionService', fn() => new PropertySubmissionService(
            $di->getShared('propertySubmissionRepository'), $di->getShared('mediaStorageService'),
            $di->getShared('telegramAutomationService'), $di->getShared('propertyAnalytics'),
        ));
        $di->setShared('propertyModerationRepository', fn() => new MysqlPropertyModerationRepository(
            $di->getShared('databaseService'), $di->getShared('mediaStorageService'),
            new MysqlLocationReference($di->getShared('databaseService')),
        ));
        $di->setShared('frontendPropertyModerationService', fn() => new PropertyModerationService(
            $di->getShared('propertyModerationRepository'), $di->getShared('telegramAutomationService'),
        ));
        $di->setShared('propertyManagementRepository', fn() => new MysqlPropertyManagementRepository(
            $di->getShared('databaseService'), $di->getShared('mediaStorageService'), $di->getShared('organizationContext')->id(),
        ));
        $di->setShared('frontendPropertyMediaService', fn() => new PropertyManagementService(
            $di->getShared('propertyManagementRepository'), $di->getShared('telegramAutomationService'),
        ));
        $di->setShared('frontendPropertyPresentationService', fn() => new PropertyPresentationService(
            $di->getShared('frontendCatalogService'), $di->getShared('databaseService'),
            $di->getShared('telegramAutomationService'), $di->getShared('propertyAnalytics'),
            new MysqlPresentationSales($di->getShared('databaseService')),
        ));
        $di->setShared('frontendN8nWebhookService', fn() => new N8nWebhookService(
            $di->getShared('databaseService'), $di->getShared('frontendContentService'),
            (string) $di->getShared('config')->integrations->n8n->inbound_secret,
            (int) $di->getShared('config')->integrations->n8n->max_clock_skew,
        ));
    }
}
