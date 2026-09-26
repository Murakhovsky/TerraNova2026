<?php
declare(strict_types=1);

namespace App\Web\Property;

use App\Security\SessionCsrfValidator;
use App\Web\Phtml\PhtmlRenderer;
use Domains\Property\Application\Contract\PropertyCatalogInterface;
use Domains\Sales\Application\Contract\SalesWriteServiceFactoryInterface;
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
        private PropertyCatalogInterface $catalog,
        private SalesWriteServiceFactoryInterface $writes,
        private SessionCsrfValidator $csrf,
        private string $organizationId,
    ) {
    }

    public function favour(Request $request): Response
    {
        $variables = $this->catalogData($request, ['page' => 1, 'per_page' => 150]);
        $variables += [
            'title' => 'Вибрані об’єкти',
            'metaTitle' => 'Вибрані об’єкти | Terra Nova CLUB',
            'metaDescription' => 'Збережені об’єкти Terra Nova CLUB.',
            'interfaceSurface' => 'public',
            'pageAssetEntries' => ['public-surface'],
        ];

        return $this->html($request, 'property/favour', $variables, (int) ($variables['_status'] ?? 200));
    }

    public function show(Request $request, string $slug): Response
    {
        $inboundRequestStatus=$this->publicLeadStatus($request);
        try {
            $property = $this->catalog->propertyBySlug($slug);
            if ($property === null) return new Response('Property was not found.', Response::HTTP_NOT_FOUND);

            $this->catalog->recordPropertyView((int) $property['id'], $this->viewContext($request));
            $images = $this->catalog->propertyImages((int) $property['id']);

            return $this->html($request, 'property/show', [
                'title' => (string) ($property['title'] ?? 'Об’єкт'),
                'interfaceSurface' => 'public',
                'pageAssetEntries' => ['public-surface', 'terranova-property-gallery'],
                'property' => $property,
                'images' => $images,
                'features' => $this->catalog->propertyFeatures((int) $property['id']),
                'groupedProperties' => $this->catalog->groupedProperties($property),
                'relatedProperties' => $this->catalog->relatedProperties($property),
                'spatialScene' => null,
                'pageStatus' => null,
                'inboundRequestStatus' => null,
                'managerClientCases' => [],
                'propertyMatchStatus' => '',
                'metaTitle' => (($property['meta_title'] ?? '') ?: ($property['title'] ?? 'Об’єкт')) . ' | Terra Nova CLUB',
                'metaDescription' => (($property['meta_description'] ?? '') ?: ($property['short_description'] ?? 'Картка об’єкта Terra Nova CLUB.')),
                'metaImage' => (string) ($images[0]['image_url'] ?? ''),
                'metaUrl' => $request->getSchemeAndHttpHost() . '/property/show/' . rawurlencode($slug),
                'metaType' => 'article',
                'analyticsPropertyId' => (int) $property['id'],
            ]);
        } catch (Throwable $error) {
            error_log('property.public.show_failed ' . $error->getMessage());
            return $this->html($request, 'error/failure', [
                'interfaceSurface' => 'public',
                'title' => 'Об’єкт тимчасово недоступний',
                'message' => 'Не вдалося завантажити картку об’єкта.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    public function presentation(Request $request, string $slug): Response
    {
        $inboundRequestStatus=$this->publicLeadStatus($request);
        try {
            $property = $this->catalog->propertyBySlug($slug);
            if ($property !== null) {
                $images = $this->catalog->propertyImages((int) $property['id']);
                $this->catalog->recordPropertyView((int) $property['id'], $this->viewContext($request));
                return $this->html($request, 'property/presentation', [
                    'interfaceSurface'=>'public','pageAssetEntries'=>['public-surface','terranova-copy'],
                    'property'=>$property,'images'=>$images,'features'=>$this->catalog->propertyFeatures((int)$property['id']),
                    'relatedProperties'=>$this->catalog->relatedProperties($property),'group'=>null,'properties'=>[],
                    'inboundRequestStatus'=>$inboundRequestStatus,'pageStatus'=>null,
                    'metaTitle'=>(($property['meta_title']??'')?:($property['title']??'Об’єкт')).' | Презентація Terra Nova CLUB',
                    'metaDescription'=>(($property['meta_description']??'')?:($property['short_description']??'Презентація об’єкта Terra Nova CLUB.')),
                    'metaImage'=>(string)($images[0]['image_url']??''),
                    'metaUrl'=>$request->getSchemeAndHttpHost().'/property/presentation/'.rawurlencode($slug),
                    'analyticsPropertyId'=>(int)$property['id'],
                ]);
            }

            $group = $this->catalog->propertyGroupBySlug($slug);
            if ($group === null) return new Response('Presentation was not found.', Response::HTTP_NOT_FOUND);

            return $this->html($request, 'property/presentation', [
                'interfaceSurface'=>'public','pageAssetEntries'=>['public-surface','terranova-copy'],
                'property'=>null,'images'=>[],'features'=>[],'relatedProperties'=>[],
                'group'=>$group,'properties'=>$this->catalog->propertyGroupPresentationProperties((int)$group['id']),
                'inboundRequestStatus'=>$inboundRequestStatus,'pageStatus'=>null,
                'metaTitle'=>(string)$group['title'].' | Презентація Terra Nova CLUB',
                'metaDescription'=>(string)(($group['description']??'')?:'Добірка опублікованих об’єктів Terra Nova CLUB.'),
                'metaUrl'=>$request->getSchemeAndHttpHost().'/property/presentation/'.rawurlencode($slug),
            ]);
        } catch (Throwable $error) {
            error_log('property.public.presentation_failed ' . $error->getMessage());
            return new Response('Presentation is temporarily unavailable.', Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    public function pdf(string $slug): Response
    {
        return new RedirectResponse('/property/presentation/' . rawurlencode($slug) . '?print=1', Response::HTTP_FOUND);
    }

    public function type(Request $request, string $code): Response
    {
        return $this->seo($request, ['type' => $code], 'Категорія', 'Об’єкти категорії', '/property/type/' . $code);
    }

    public function city(Request $request, string $slug): Response
    {
        return $this->seo($request, ['location' => $slug], 'Місто', 'Об’єкти у місті', '/property/city/' . $slug);
    }

    public function landing(Request $request, string $location, string $type): Response
    {
        return $this->seo($request, ['location' => $location, 'type' => $type], 'Локальна добірка', 'Нерухомість', '/nerukhomist/' . $location . '/' . $type);
    }

    public function submit(Request $request): Response
    {
        $status = null;
        $httpStatus = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            $status = 'Публічна подача об’єкта тимчасово переведена на новий canonical Property intake. Форма не втратила введені дані, але запис зараз не створено.';
            $httpStatus = Response::HTTP_SERVICE_UNAVAILABLE;
        }

        return $this->html($request, 'property/submit', [
            'title'=>'Подати об’єкт','interfaceSurface'=>'public','pageAssetEntries'=>['public-surface'],
            'submissionStatus'=>$status,'pageStatus'=>null,'formData'=>$request->request->all(),
            'types'=>$this->catalog->propertyTypes(),
            'metaTitle'=>'Подати об’єкт | Terra Nova CLUB',
            'metaDescription'=>'Форма для власників, партнерів і рієлторів.',
            'metaUrl'=>$request->getSchemeAndHttpHost().'/property/submit',
        ], $httpStatus);
    }

    public function presentationShare(Request $request): Response
    {
        $tenant=$this->manager();
        if($tenant instanceof Response)return $tenant;
        if(!$this->csrf->isValid($request))return new Response('Invalid CSRF token.',Response::HTTP_FORBIDDEN);
        $slug=trim((string)$request->request->get('slug',''));
        if($slug===''||!preg_match('/^[A-Za-z0-9_-]+$/',$slug))return new Response('Invalid property slug.',Response::HTTP_BAD_REQUEST);
        return new RedirectResponse('/property/presentation/'.rawurlencode($slug));
    }

    private function seo(Request $request, array $overrides, string $kicker, string $title, string $path): Response
    {
        $variables = $this->catalogData($request, $overrides);
        $variables += [
            'interfaceSurface'=>'public','pageAssetEntries'=>['public-surface'],
            'seoKicker'=>$kicker,'seoTitle'=>$title,'seoDescription'=>'Добірка актуальних об’єктів Terra Nova CLUB.',
            'metaTitle'=>$title.' | Terra Nova CLUB','metaDescription'=>'Добірка актуальних об’єктів Terra Nova CLUB.',
            'metaUrl'=>$request->getSchemeAndHttpHost().$path,
        ];
        return $this->html($request, 'property/seo', $variables, (int)($variables['_status']??200));
    }

    private function catalogData(Request $request, array $overrides = []): array
    {
        $filters = array_replace($this->catalog->filtersFromQuery($request->query->all()), $overrides);
        try {
            $count = $this->catalog->catalogCount($filters);
            $pagination = $this->catalog->catalogPagination($filters, $count);
            $filters['page'] = $pagination['page'];
            $filters['per_page'] = $pagination['per_page'];
            return [
                'catalogStatus'=>null,'filters'=>$filters,'types'=>$this->catalog->propertyTypes(),
                'locations'=>$this->catalog->locations(),'properties'=>$this->catalog->catalogProperties($filters),
                'resultCount'=>$count,'pagination'=>$pagination,'catalogStats'=>$this->catalog->catalogStats($filters),
                '_status'=>Response::HTTP_OK,
            ];
        } catch (Throwable $error) {
            error_log('property.public.catalog_failed ' . $error->getMessage());
            return [
                'catalogStatus'=>'Дані об’єктів тимчасово недоступні.','filters'=>$filters,'types'=>[],'locations'=>[],
                'properties'=>[],'resultCount'=>0,'pagination'=>[],'catalogStats'=>[],'_status'=>Response::HTTP_SERVICE_UNAVAILABLE,
            ];
        }
    }

    private function listingUser(): TenantContext|Response
    {
        $tenant=$this->tenants->current();
        if($tenant===null)return new RedirectResponse('/auth/login');
        if(!in_array($tenant->role()->value(),['realtor','developer','partner','manager','admin'],true))return new Response('Forbidden',Response::HTTP_FORBIDDEN);
        return $tenant;
    }

    private function publicLeadStatus(Request $request): ?string
    {
        if(!$request->isMethod('POST'))return null;
        try{
            $result=$this->writes->forOrganization($this->organizationId)->receivePublicLead($request->request->all(),$request->getRequestUri());
            return $result->ok
                ? 'Заявку прийнято. Менеджер зв’яжеться з вами.'
                : match($result->code){
                    'contact_required'=>'Вкажіть ім’я та телефон або email.',
                    'invalid_email'=>'Перевірте email.',
                    default=>'Заявку не вдалося зберегти.',
                };
        }catch(Throwable $error){
            error_log('property.public.lead_failed '.$error->getMessage());
            return 'Заявку не вдалося зберегти.';
        }
    }

    private function manager(): TenantContext|Response
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) return new RedirectResponse('/auth/login');
        if (!$tenant->isManager()) return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        return $tenant;
    }

    private function viewContext(Request $request): array
    {
        return [
            'source_page'=>$request->getRequestUri(),
            'referer'=>(string)$request->headers->get('referer',''),
            'utm_source'=>(string)$request->query->get('utm_source',''),
            'utm_medium'=>(string)$request->query->get('utm_medium',''),
            'utm_campaign'=>(string)$request->query->get('utm_campaign',''),
        ];
    }

    private function html(Request $request, string $view, array $variables, int $status = 200): Response
    {
        unset($variables['_status']);
        return new Response($this->renderer->render($request, $view, $variables), $status, ['Content-Type'=>'text/html; charset=UTF-8']);
    }
}
