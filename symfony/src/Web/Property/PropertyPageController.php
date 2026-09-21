<?php
declare(strict_types=1);

namespace App\Web\Property;

use App\Web\Navigation\NavigationBuilder;
use App\Web\Phtml\PhtmlRenderer;
use Domains\Property\Application\Contract\PropertyCatalogInterface;
use Domains\Property\Application\Contract\PropertyManagementInterface;
use Domains\Property\Application\Contract\PropertyModerationInterface;
use Domains\Sales\Application\Contract\ClientCaseReadModelFactoryInterface;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class PropertyPageController
{
    public function __construct(
        private PhtmlRenderer $renderer,
        private TenantContextProviderInterface $tenants,
        private NavigationBuilder $navigation,
        private PropertyCatalogInterface $catalog,
        private PropertyManagementInterface $management,
        private PropertyModerationInterface $moderation,
        private ClientCaseReadModelFactoryInterface $cases,
    ) {
    }

    public function catalog(Request $request): Response
    {
        $variables = $this->catalogData($request);
        $variables += [
            'title' => 'Каталог нерухомості',
            'metaTitle' => 'Каталог нерухомості Terra Nova CLUB',
            'metaDescription' => 'Нерухомість для купівлі, оренди та інвестицій.',
            'metaUrl' => $request->getSchemeAndHttpHost() . '/property/catalog',
            'interfaceSurface' => 'public',
            'pageAssetEntries' => ['terranova-catalog-api'],
            'inboundRequestStatus' => null,
            'managerClientCases' => [],
            'propertyMatchStatus' => (string) $request->query->get('status_message', ''),
        ];

        $tenant = $this->tenants->current();
        if ($tenant !== null && $tenant->isManager()) {
            try {
                $variables['managerClientCases'] = $this->cases->forOrganization($tenant->organizationId()->value())->openCaseOptions();
                $variables['currentUser'] = ['id' => (int) $tenant->userId()->value(), 'role' => $tenant->role()->value()];
            } catch (Throwable) {
            }
        }

        return $this->html($request, 'property/catalog', $variables, (int) ($variables['_status'] ?? 200));
    }

    public function map(Request $request): Response
    {
        $variables = $this->catalogData($request);
        $variables += [
            'title' => 'Карта об’єктів',
            'metaTitle' => 'Карта об’єктів | Terra Nova CLUB',
            'metaDescription' => 'Карта об’єктів Terra Nova CLUB із фільтрами за типом, містом і бюджетом.',
            'interfaceSurface' => 'public',
            'pageAssetEntries' => ['terranova-catalog-api'],
        ];
        return $this->html($request, 'property/map', $variables, (int) ($variables['_status'] ?? 200));
    }

    public function manage(Request $request): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;
        $filters = $this->management->adminFilters($request->query->all());

        try {
            return $this->workspace($request, $tenant, 'Керування об’єктами', 'objects', 'property/manage', [
                'filters' => $filters,
                'operationalStageRules' => $this->management->operationalStageRules(),
                'types' => $this->catalog->propertyTypes(),
                'locations' => $this->catalog->locations(),
                'propertyGroups' => $this->management->propertyGroups(false),
                'agents' => $this->management->agents(),
                'properties' => $this->management->adminProperties($filters),
                'stats' => $this->management->adminStats(),
                'qualityStats' => $this->management->adminQualityStats($filters),
                'pageStatus' => null,
                'actionStatus' => (string) $request->query->get('status_message', ''),
            ]);
        } catch (Throwable $error) {
            error_log('property.manage.read_failed ' . $error->getMessage());
            return $this->workspace($request, $tenant, 'Керування об’єктами', 'objects', 'property/manage', [
                'filters'=>$filters,'operationalStageRules'=>[],'types'=>[],'locations'=>[],'propertyGroups'=>[],
                'agents'=>[],'properties'=>[],'stats'=>[],'qualityStats'=>[],
                'pageStatus'=>'Реєстр об’єктів тимчасово недоступний.','actionStatus'=>'',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    public function listing(Request $request): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;
        $filters = $this->management->adminFilters($request->query->all());
        $user = ['id' => (int) $tenant->userId()->value(), 'role' => $tenant->role()->value()];

        try {
            return $this->workspace($request, $tenant, 'Внутрішній MLS / Listing', 'listing', 'property/listing', [
                'filters' => $filters,
                'types' => $this->catalog->propertyTypes(),
                'locations' => $this->catalog->locations(),
                'propertyGroups' => $this->management->propertyGroups(false),
                'agents' => $this->management->agents(),
                'managerClientCases' => $this->cases->forOrganization($tenant->organizationId()->value())->openCaseOptions(),
                'properties' => $this->management->listingProperties($filters, $user),
                'stats' => $this->management->adminStats(),
                'pageStatus' => null,
                'actionStatus' => (string) $request->query->get('status_message', ''),
                'canEditListing' => true,
            ]);
        } catch (Throwable $error) {
            error_log('property.listing.read_failed ' . $error->getMessage());
            return $this->workspace($request, $tenant, 'Внутрішній MLS / Listing', 'listing', 'property/listing', [
                'filters'=>$filters,'types'=>[],'locations'=>[],'propertyGroups'=>[],'agents'=>[],
                'managerClientCases'=>[],'properties'=>[],'stats'=>[],'pageStatus'=>'Внутрішній Listing тимчасово недоступний.',
                'actionStatus'=>'','canEditListing'=>true,
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    public function submissions(Request $request): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;
        $status = trim((string) $request->query->get('status', ''));

        try {
            return $this->workspace($request, $tenant, 'Модерація об’єктів', 'submissions', 'property/submissions', [
                'status' => $status,
                'submissions' => $this->moderation->submissions($status),
                'counts' => $this->moderation->counts(),
                'pageStatus' => null,
            ]);
        } catch (Throwable $error) {
            error_log('property.submissions.read_failed ' . $error->getMessage());
            return $this->workspace($request, $tenant, 'Модерація об’єктів', 'submissions', 'property/submissions', [
                'status'=>$status,'submissions'=>[],'counts'=>[],
                'pageStatus'=>'Заявки тимчасово недоступні. Спробуйте оновити сторінку трохи пізніше.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    /** @return array<string,mixed> */
    private function catalogData(Request $request): array
    {
        $filters = $this->catalog->filtersFromQuery($request->query->all());
        try {
            $count = $this->catalog->catalogCount($filters);
            $pagination = $this->catalog->catalogPagination($filters, $count);
            $filters['page'] = $pagination['page'];
            $filters['per_page'] = $pagination['per_page'];
            return [
                'catalogStatus' => null,
                'filters' => $filters,
                'types' => $this->catalog->propertyTypes(),
                'locations' => $this->catalog->locations(),
                'properties' => $this->catalog->catalogProperties($filters),
                'resultCount' => $count,
                'pagination' => $pagination,
                'catalogStats' => $this->catalog->catalogStats($filters),
                '_status' => Response::HTTP_OK,
            ];
        } catch (Throwable $error) {
            error_log('property.catalog.read_failed ' . $error->getMessage());
            return [
                'catalogStatus'=>'Дані об’єктів тимчасово недоступні.','filters'=>$filters,'types'=>[],'locations'=>[],
                'properties'=>[],'resultCount'=>0,'pagination'=>[],'catalogStats'=>[],'_status'=>Response::HTTP_SERVICE_UNAVAILABLE,
            ];
        }
    }

    private function manager(): TenantContext|Response
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) return new RedirectResponse('/auth/login');
        if (!$tenant->isManager()) return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        return $tenant;
    }

    /** @param array<string,mixed> $extra */
    private function workspace(Request $request, TenantContext $tenant, string $title, string $active, string $view, array $extra, int $status = 200): Response
    {
        $role = $tenant->role()->value();
        $variables = array_replace([
            'title'=>$title,'metaTitle'=>$title . ' | Terra Nova COS','metaRobots'=>'noindex,nofollow',
            'interfaceSurface'=>'workspace','workspaceSection'=>'properties','workspaceActive'=>$active,
            'workspaceActiveSection'=>$this->navigation->activeSection($active),'pageAssetEntries'=>['property-workspace'],
            'currentUser'=>['id'=>(int)$tenant->userId()->value(),'role'=>$role],'role'=>$role,'isTeam'=>true,
            'isAdmin'=>$tenant->isAdmin(),'workspaceNavigation'=>$this->navigation->workspace($tenant),
        ], $extra);
        return $this->html($request, $view, $variables, $status);
    }

    /** @param array<string,mixed> $variables */
    private function html(Request $request, string $view, array $variables, int $status): Response
    {
        unset($variables['_status']);
        return new Response($this->renderer->render($request, $view, $variables), $status, ['Content-Type'=>'text/html; charset=UTF-8']);
    }
}
