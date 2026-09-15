<?php

declare(strict_types=1);

namespace Interfaces\Web\Controller;

use Throwable;

final class PublicPropertyController extends ControllerBase
{
    public function catalogAction(): void
    {
        $this->preparePublicSurface('Каталог нерухомості', ['terranova-catalog-api']);
        $this->view->pick('property/catalog');
        $this->view->inboundRequestStatus = null;
        $this->view->catalogStatus = null;
        $this->view->propertyMatchStatus = null;
        $this->view->filters = $this->catalogService()->filtersFromQuery((array) $this->request->getQuery());
        $this->view->properties = [];
        $this->view->types = [];
        $this->view->locations = [];
        $this->view->resultCount = 0;
        $this->view->pagination = [];
        $this->view->catalogStats = [];
        $this->view->metaTitle = 'Каталог нерухомості Terra Nova CLUB';
        $this->view->metaDescription = 'Нерухомість для купівлі, оренди та інвестицій: фільтри, актуальні картки, карта, вибране та швидкий запит.';
        $this->view->metaUrl = $this->absoluteUrl($this->request->getURI());

        try {
            if ($this->request->isPost()) {
                $this->view->inboundRequestStatus = $this->submitInboundRequest();
            }

            $filters = (array) $this->view->filters;
            $this->view->types = $this->catalogService()->propertyTypes();
            $this->view->locations = $this->catalogService()->locations();
            $this->view->resultCount = $this->catalogService()->catalogCount($filters);
            $this->view->catalogStats = $this->catalogService()->catalogStats($filters);
            $this->view->pagination = $this->catalogService()->catalogPagination($filters, (int) $this->view->resultCount);
            $this->view->properties = $this->catalogService()->catalogProperties($filters);
        } catch (Throwable $e) {
            $this->logFrontendError('public-catalog-page', $e);
            $this->response->setStatusCode(503, 'Service Unavailable');
            $this->view->catalogStatus = 'Каталог тимчасово недоступний. Спробуйте оновити сторінку трохи пізніше.';

            if ($this->request->isPost()) {
                $this->view->inboundRequestStatus = 'Заявку не вдалося зберегти. Спробуйте ще раз або зв’яжіться з нами напряму.';
            }
        }
    }

    public function mapAction(): void
    {
        $this->preparePublicSurface('Карта об’єктів');
        $this->view->pick('property/map');
        $this->view->catalogStatus = null;
        $this->view->filters = $this->catalogService()->filtersFromQuery((array) $this->request->getQuery());
        $this->view->properties = [];
        $this->view->metaTitle = 'Карта об’єктів | Terra Nova CLUB';
        $this->view->metaDescription = 'Карта опублікованих об’єктів Terra Nova CLUB на основі фактичних географічних координат.';
        $this->view->metaUrl = $this->absoluteUrl($this->request->getURI());

        try {
            $filters = (array) $this->view->filters;
            $filters['page'] = 1;
            $filters['per_page'] = 100;
            $this->view->properties = $this->catalogService()->catalogProperties($filters);
        } catch (Throwable $e) {
            $this->logFrontendError('public-map-page', $e);
            $this->response->setStatusCode(503, 'Service Unavailable');
            $this->view->catalogStatus = 'Карта тимчасово недоступна. Перейдіть до каталогу або спробуйте пізніше.';
        }
    }

    public function showAction(?string $slug = null): void
    {
        $slug = $slug ?: (string) $this->dispatcher->getParam('slug');
        $this->preparePublicSurface('Об’єкт нерухомості', ['terranova-property-gallery']);
        $this->view->pick('property/show');
        $this->view->property = null;
        $this->view->images = [];
        $this->view->features = [];
        $this->view->groupedProperties = [];
        $this->view->relatedProperties = [];
        $this->view->spatialScene = null;
        $this->view->pageStatus = null;
        $this->view->inboundRequestStatus = null;
        $this->view->propertyMatchStatus = null;

        try {
            if ($this->request->isPost()) {
                $this->view->inboundRequestStatus = $this->submitInboundRequest();
            }

            $property = $this->catalogService()->propertyBySlug($slug);
            if (!$property) {
                $this->response->setStatusCode(404, 'Not Found');
                return;
            }

            $this->catalogService()->recordPropertyView((int) $property['id'], $this->viewContext());
            $this->view->property = $property;
            $this->view->images = $this->catalogService()->propertyImages((int) $property['id']);
            $this->view->features = $this->catalogService()->propertyFeatures((int) $property['id']);
            $this->view->groupedProperties = $this->catalogService()->groupedProperties($property);
            $this->view->relatedProperties = $this->catalogService()->relatedProperties($property);
            $this->view->spatialScene = $this->spatialSceneService()->sceneForProperty((int) $property['id'], true);

            if ($this->view->spatialScene) {
                $this->view->pageAssetEntries = array_values(array_unique(array_merge(
                    (array) $this->view->pageAssetEntries,
                    ['spatial-viewer']
                )));
            }

            $this->view->metaTitle = ($property['meta_title'] ?: $property['title']) . ' | Terra Nova CLUB';
            $this->view->metaDescription = $property['meta_description'] ?: ($property['short_description'] ?: 'Картка об’єкта Terra Nova CLUB з фото, характеристиками та запитом на перегляд.');
            $this->view->metaImage = $this->absoluteUrl((string) ($this->view->images[0]['image_url'] ?? 'img/terra-nova-og.jpg'));
            $this->view->metaUrl = $this->absoluteUrl('property/show/' . (string) $property['slug']);
            $this->view->metaType = 'article';
            $this->view->analyticsPropertyId = (int) $property['id'];
        } catch (Throwable $e) {
            $this->logFrontendError('public-property-page', $e);
            $this->response->setStatusCode(503, 'Service Unavailable');
            $this->view->pageStatus = 'Сторінка об’єкта тимчасово недоступна. Спробуйте оновити її трохи пізніше.';

            if ($this->request->isPost()) {
                $this->view->inboundRequestStatus = 'Заявку не вдалося зберегти. Спробуйте ще раз або зв’яжіться з нами напряму.';
            }
        }
    }

    public function presentationAction(?string $slug = null): void
    {
        $slug = $slug ?: (string) $this->dispatcher->getParam('slug');
        $this->preparePublicSurface('Презентація об’єкта', ['terranova-copy']);
        $this->view->pick('property/presentation');
        $this->view->property = null;
        $this->view->images = [];
        $this->view->features = [];
        $this->view->relatedProperties = [];
        $this->view->group = null;
        $this->view->properties = [];
        $this->view->inboundRequestStatus = null;
        $this->view->pageStatus = null;
        $this->view->metaTitle = 'Презентація об’єкта | Terra Nova CLUB';
        $this->view->metaDescription = 'Коротка презентація об’єкта Terra Nova CLUB для клієнта.';
        $this->view->metaUrl = $this->absoluteUrl('property/presentation/' . $slug);

        try {
            if ($this->request->isPost()) {
                $this->view->inboundRequestStatus = $this->submitInboundRequest();
            }

            $property = $this->catalogService()->propertyBySlug($slug);
            if ($property) {
                $this->catalogService()->recordPropertyView((int) $property['id'], $this->viewContext());
                $this->view->property = $property;
                $this->view->images = $this->catalogService()->propertyImages((int) $property['id']);
                $this->view->features = $this->catalogService()->propertyFeatures((int) $property['id']);
                $this->view->relatedProperties = $this->catalogService()->relatedProperties($property);
                $this->view->metaTitle = ($property['meta_title'] ?: $property['title']) . ' | Презентація Terra Nova CLUB';
                $this->view->metaDescription = $property['meta_description'] ?: ($property['short_description'] ?: 'Коротка презентація об’єкта Terra Nova CLUB з фото, ціною, параметрами та запитом.');
                $this->view->metaImage = $this->absoluteUrl((string) ($this->view->images[0]['image_url'] ?? 'img/terra-nova-og.jpg'));
                $this->view->analyticsPropertyId = (int) $property['id'];
                return;
            }

            $group = $this->catalogService()->propertyGroupBySlug($slug);
            if (!$group) {
                $this->response->setStatusCode(404, 'Not Found');
                return;
            }

            $this->view->group = $group;
            $this->view->properties = $this->catalogService()->propertyGroupPresentationProperties((int) $group['id']);
            $this->view->metaTitle = $group['title'] . ' | Презентація Terra Nova CLUB';
            $this->view->metaDescription = $group['description'] ?: 'Добірка опублікованих об’єктів за однією адресою або в одному проєкті.';
        } catch (Throwable $e) {
            $this->logFrontendError('public-property-presentation', $e);
            $this->response->setStatusCode(503, 'Service Unavailable');
            $this->view->pageStatus = 'Презентація тимчасово недоступна. Спробуйте оновити сторінку трохи пізніше.';
        }
    }

    public function submitAction(): void
    {
        $this->preparePublicSurface('Подати об’єкт');
        $this->view->pick('property/submit');
        $this->view->submissionStatus = null;
        $this->view->pageStatus = null;
        $this->view->formData = (array) $this->request->getPost();
        $this->view->types = [];
        $this->view->metaTitle = 'Подати об’єкт | Terra Nova CLUB';
        $this->view->metaDescription = 'Форма для власників, партнерів і рієлторів, які хочуть подати об’єкт у каталог Terra Nova CLUB.';
        $this->view->metaUrl = $this->absoluteUrl('property/submit');

        try {
            $this->view->types = $this->catalogService()->propertyTypes();
            if ($this->request->isPost()) {
                $this->view->submissionStatus = $this->submitPropertySubmission();
            }
        } catch (Throwable $e) {
            $this->logFrontendError('public-property-submit', $e);
            $this->response->setStatusCode(503, 'Service Unavailable');
            $this->view->pageStatus = 'Сторінка подачі об’єкта тимчасово недоступна. Спробуйте оновити її трохи пізніше.';

            if ($this->request->isPost()) {
                $this->view->submissionStatus = 'Об’єкт не вдалося зберегти. Спробуйте ще раз або зв’яжіться з нами напряму.';
            }
        }
    }

    private function preparePublicSurface(string $title, array $assets = []): void
    {
        $this->view->title = $title;
        $this->view->interfaceSurface = 'public';
        $this->view->pageAssetEntries = array_values(array_unique(array_merge(['public-surface'], $assets)));
    }

    private function viewContext(): array
    {
        return [
            'source_page' => $this->request->getURI(),
            'referer' => (string) ($_SERVER['HTTP_REFERER'] ?? ''),
            'utm_source' => (string) $this->request->getQuery('utm_source', 'string', ''),
            'utm_medium' => (string) $this->request->getQuery('utm_medium', 'string', ''),
            'utm_campaign' => (string) $this->request->getQuery('utm_campaign', 'string', ''),
        ];
    }

    private function absoluteUrl(string $path = ''): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8001';

        return $scheme . '://' . $host . '/' . ltrim($path, '/');
    }
}
