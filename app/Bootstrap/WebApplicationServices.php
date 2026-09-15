<?php
declare(strict_types=1);

namespace Bootstrap;

use Domains\Sales\Infrastructure\Persistence\MySql\MysqlPresentationSales;
use Domains\Identity\Infrastructure\Persistence\MySql\OrganizationMembershipSynchronizer;
use Domains\Identity\Infrastructure\ReadModel\MySql\MembershipSynchronizedAdminDashboardService;
use Infrastructure\Platform\Analytics\MysqlPropertyFunnelAnalytics;
use Infrastructure\Platform\Analytics\MysqlPropertyAnalytics;
use Domains\Property\Infrastructure\ReadModel\MySql\CatalogService;
use Domains\Property\Infrastructure\ReadModel\MySql\MysqlPropertyWorkspaceReadModel;
use Interfaces\Web\Navigation\DiagnosticNavigationContributor;
use Interfaces\Web\Navigation\ModuleAwareNavigationService;
use Interfaces\Web\Navigation\PropertyNavigationContributor;
use Interfaces\Web\Navigation\SalesNavigationContributor;
use Interfaces\Web\Routing\DiagnosticModuleRouteContributor;
use Interfaces\Web\Routing\ModuleRouteAccessGuard;
use Interfaces\Web\Routing\ModuleRouteRegistrar;
use Interfaces\Web\Routing\PropertyModuleRouteContributor;
use Interfaces\Web\Routing\SalesModuleRouteContributor;
use Interfaces\Web\Service\ClientCaseService;
use Interfaces\Web\Service\CompanyHomeService;
use Domains\Content\Application\Service\ContentService;
use Domains\Content\Infrastructure\Persistence\MySql\MysqlContentRepository;
use Interfaces\Web\Service\InboundRequestService;
use Infrastructure\Integration\N8n\N8nWebhookService;
use Domains\Property\Application\Service\PropertyManagementService;
use Domains\Property\Infrastructure\Persistence\MySql\MysqlPropertyManagementRepository;
use Domains\Property\Infrastructure\Persistence\MySql\Management\CanonicalPropertyManagementWorkflowRepository;
use Domains\Property\Infrastructure\Persistence\MySql\Management\CanonicalPropertyManagementWriteRepository;
use Domains\Property\Infrastructure\Persistence\MySql\Management\ComposedPropertyManagementRepository;
use Domains\Property\Infrastructure\Persistence\MySql\Management\LegacyPropertyGroupManagementRepository;
use Domains\Property\Infrastructure\Persistence\MySql\Management\LegacyPropertyManagementReadRepository;
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
        $di->setShared('moduleRouteAccessGuard', fn() => new ModuleRouteAccessGuard(
            $di->getShared('organizationContext'),
            $di->getShared('cosActiveModuleResolver'),
        ));
        $di->setShared('moduleRouteRegistrar', fn() => new ModuleRouteRegistrar(
            $di->getShared('moduleRouteAccessGuard'),
        ));
        $di->setShared('salesRouteContributor', fn() => new SalesModuleRouteContributor());
        $di->setShared('diagnosticRouteContributor', fn() => new DiagnosticModuleRouteContributor());
        $di->setShared('propertyRouteContributor', fn() => new PropertyModuleRouteContributor());

        $di->setShared('salesNavigationContributor', fn() => new SalesNavigationContributor());
        $di->setShared('propertyNavigationContributor', fn() => new PropertyNavigationContributor());
        $di->setShared('diagnosticNavigationContributor', fn() => new DiagnosticNavigationContributor());
        $di->setShared('frontendNavigationService', fn() => new ModuleAwareNavigationService(
            $di->getShared('organizationContext'),
            $di->getShared('cosActiveModuleResolver'),
            $di->getShared('cosModuleWebNavigationContributors'),
        ));

        $di->setShared('identityOrganizationMembershipSynchronizer', fn() => new OrganizationMembershipSynchronizer(
            $di->getShared('databaseService'),
        ));
        $di->setShared('frontendAdminDashboardService', fn() => new MembershipSynchronizedAdminDashboardService(
            $di->getShared('databaseService'),
            $di->getShared('identityOrganizationMembershipSynchronizer'),
        ));
        $di->setShared('frontendAnalyticsService', fn() => new MysqlPropertyFunnelAnalytics($di->getShared('databaseService')));
        $di->setShared('frontendPublicPageService', fn() => new PublicPageService());
        $di->setShared('contentRepository', fn() => new MysqlContentRepository(
            $di->getShared('databaseService'), new MysqlContentIntegrationOutbox($di->getShared('databaseService')),
        ));
        $di->setShared('frontendContentService', fn() => new ContentService($di->getShared('contentRepository')));
        $di->setShared('propertyAnalytics', fn() => new MysqlPropertyAnalytics($di->getShared('databaseService')));
        $di->setShared('frontendCatalogService', fn() => new CatalogService($di->getShared('databaseService'), $di->getShared('propertyAnalytics')));
        $di->setShared('propertyWorkspaceReadModel', fn() => new MysqlPropertyWorkspaceReadModel($di->getShared('databaseService')));
        $di->setShared('frontendCompanyHomeService', fn() => new CompanyHomeService(
            $di->getShared('salesWorkspaceReadModel'),
            $di->getShared('propertyWorkspaceReadModel'),
            $di->getShared('cosOperationsReadModel'),
            $di->getShared('cosActiveModuleResolver'),
        ));
        $di->setShared('frontendClientCaseService', fn() => new ClientCaseService(
            $di->getShared('salesClientCaseReadModel'), $di->getShared('salesClientCaseService'), $di->getShared('salesInboundService'),
        ));
        $di->setShared('frontendInboundRequestService', fn() => new InboundRequestService($di->getShared('salesReceivePublicLead')));

        $di->setShared('propertySubmissionRepository', fn() => new MysqlPropertySubmissionRepository(
            $di->getShared('databaseService'), $di->getShared('organizationContext')->id(),
        ));
        $di->setShared('frontendPropertySubmissionService', fn() => new PropertySubmissionService(
            $di->getShared('propertySubmissionRepository'), $di->getShared('mediaStorageService'), null, $di->getShared('propertyAnalytics'),
        ));
        $di->setShared('propertyModerationRepository', fn() => new MysqlPropertyModerationRepository(
            $di->getShared('databaseService'),
            $di->getShared('mediaStorageService'),
            new MysqlLocationReference($di->getShared('databaseService')),
            $di->getShared('organizationContext')->id(),
        ));
        $di->setShared('frontendPropertyModerationService', fn() => new PropertyModerationService(
            $di->getShared('propertyModerationRepository'),
            null,
            $di->getShared('propertyIdentityWorkflow'),
        ));

        // V0.12 cutover: legacy repository remains a read/media compatibility backend only.
        // Authoritative Asset/Inventory/Listing mutations flow through propertyCanonicalRuntime.
        $di->setShared('propertyManagementLegacyBackend', fn() => new MysqlPropertyManagementRepository(
            $di->getShared('databaseService'), $di->getShared('mediaStorageService'), $di->getShared('organizationContext')->id(),
        ));
        $di->setShared('propertyManagementRepository', fn() => new ComposedPropertyManagementRepository(
            new LegacyPropertyManagementReadRepository($di->getShared('propertyManagementLegacyBackend')),
            new LegacyPropertyGroupManagementRepository($di->getShared('propertyManagementLegacyBackend')),
            new CanonicalPropertyManagementWriteRepository(
                $di->getShared('propertyCanonicalRuntime'),
                $di->getShared('propertyManagementLegacyBackend'),
                $di->getShared('propertyCompatibilityProjection'),
                $di->getShared('databaseService')->connection(),
                $di->getShared('organizationContext')->id(),
            ),
            new CanonicalPropertyManagementWorkflowRepository(
                $di->getShared('propertyCanonicalRuntime'),
                $di->getShared('propertyManagementLegacyBackend'),
                $di->getShared('propertyCompatibilityProjection'),
                $di->getShared('organizationContext')->id(),
            ),
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
