<?php
declare(strict_types=1);

namespace Modules\Frontend\Controllers;

use Throwable;

class PropertyController extends ControllerBase
{
    public function catalogAction(): void
    {
        $this->view->inboundRequestStatus = null;
        $this->view->catalogStatus = null;
        $this->view->filters = $this->catalogService()->filtersFromQuery((array) $this->request->getQuery());
        $this->view->properties = [];
        $this->view->resultCount = 0;
        $this->view->catalogStats = [];
        $this->view->managerClientCases = [];
        $this->view->propertyMatchStatus = (string) $this->request->getQuery('status_message', 'string', '');
        $this->view->metaTitle = 'Каталог нерухомості Terra Nova CLUB';
        $this->view->metaDescription = 'Нерухомість для купівлі, оренди та інвестицій: зручні фільтри, медіа, картки об’єктів, карта, вибране та швидкий запит.';
        $this->view->metaUrl = $this->absoluteUrl($this->request->getURI());

        try {
            if ($this->request->isPost()) {
                $this->view->inboundRequestStatus = $this->submitInboundRequest();
            }

            $this->view->types = $this->catalogService()->propertyTypes();
            $this->view->locations = $this->catalogService()->locations();
            $this->view->properties = $this->catalogService()->catalogProperties($this->view->filters);
            $this->view->resultCount = $this->catalogService()->catalogCount($this->view->filters);
            $this->view->catalogStats = $this->catalogService()->catalogStats($this->view->filters);

            if ($this->authService()->isManager($this->currentUser())) {
                $this->view->managerClientCases = $this->clientCaseService()->openCaseOptions();
            }
        } catch (Throwable $e) {
            $this->logFrontendError('catalog-page', $e);
            $this->response->setStatusCode(503, 'Service Unavailable');
            $this->view->catalogStatus = 'Каталог тимчасово недоступний. Ми вже знаємо про проблему й відновимо дані.';

            if ($this->request->isPost()) {
                $this->view->inboundRequestStatus = 'Заявку не вдалося зберегти. Спробуйте ще раз або напишіть нам напряму.';
            }
        }
    }

    public function showAction(?string $slug = null): void
    {
        $slug = $slug ?: (string) $this->dispatcher->getParam('slug');
        $this->view->inboundRequestStatus = null;
        $this->view->pageStatus = null;
        $this->view->property = null;
        $this->view->images = [];
        $this->view->types = [];
        $this->view->locations = [];
        $this->view->features = [];
        $this->view->relatedProperties = [];
        $this->view->managerClientCases = [];
        $this->view->propertyMatchStatus = (string) $this->request->getQuery('status_message', 'string', '');

        try {
            if ($this->request->isPost()) {
                $this->view->inboundRequestStatus = $this->submitInboundRequest();
            }

            $property = $this->catalogService()->propertyBySlug($slug);

            if (!$property) {
                $this->response->setStatusCode(404, 'Not Found');
                return;
            }

            $this->view->property = $property;
            $this->view->images = $this->catalogService()->propertyImages((int) $property['id']);
            $this->view->features = $this->catalogService()->propertyFeatures((int) $property['id']);
            $this->view->relatedProperties = $this->catalogService()->relatedProperties($property);

            if ($this->authService()->isManager($this->currentUser())) {
                $this->view->managerClientCases = $this->clientCaseService()->openCaseOptions();
            }

            $this->view->metaTitle = ($property['meta_title'] ?: $property['title']) . ' | Terra Nova CLUB';
            $this->view->metaDescription = $property['meta_description'] ?: ($property['short_description'] ?: 'Картка об’єкта Terra Nova CLUB з фото, характеристиками та запитом на перегляд.');
            $this->view->metaImage = $this->absoluteUrl((string) ($this->view->images[0]['image_url'] ?? 'img/terra-nova-og.jpg'));
            $this->view->metaUrl = $this->absoluteUrl('property/show/' . $property['slug']);
            $this->view->metaType = 'article';
        } catch (Throwable $e) {
            $this->logFrontendError('property-page', $e);
            $this->response->setStatusCode(503, 'Service Unavailable');
            $this->view->pageStatus = 'Сторінка об’єкта тимчасово недоступна. Спробуйте оновити її трохи пізніше.';

            if ($this->request->isPost()) {
                $this->view->inboundRequestStatus = 'Заявку не вдалося зберегти. Спробуйте ще раз або напишіть нам напряму.';
            }
        }
    }

    public function createAction(): void
    {
        $this->dispatcher->forward([
            'controller' => 'property',
            'action' => 'submit',
        ]);
    }

    public function submitAction(): void
    {
        $this->view->pick('property/submit');
        $this->view->title = 'Подати об’єкт';
        $this->view->submissionStatus = null;
        $this->view->pageStatus = null;
        $this->view->formData = (array) $this->request->getPost();
        $this->view->metaTitle = 'Подати об’єкт | Terra Nova CLUB';
        $this->view->metaDescription = 'Форма для власників, партнерів і рієлторів, які хочуть подати об’єкт у каталог Terra Nova CLUB.';
        $this->view->metaUrl = $this->absoluteUrl('property/submit');

        try {
            $this->view->types = $this->catalogService()->propertyTypes();

            if ($this->request->isPost()) {
                $this->view->submissionStatus = $this->submitPropertySubmission();
            }
        } catch (Throwable $e) {
            $this->logFrontendError('property-submit-page', $e);
            $this->response->setStatusCode(503, 'Service Unavailable');
            $this->view->types = [];
            $this->view->pageStatus = 'Сторінка подачі об’єкта тимчасово недоступна. Спробуйте оновити її трохи пізніше.';

            if ($this->request->isPost()) {
                $this->view->submissionStatus = 'Об’єкт не вдалося зберегти. Спробуйте ще раз або напишіть нам напряму.';
            }
        }
    }

    public function listingAction(): void
    {
        $this->view->title = 'Listing / MLS';
        $this->view->metaTitle = 'Listing / MLS | Terra Nova CLUB';
        $this->view->metaDescription = 'Табличне представлення каталогу Terra Nova CLUB для швидкої роботи з об’єктами.';
        $this->loadPropertyWorkspace('listing-page');
    }

    public function manageAction(): void
    {
        if (!$this->requireManager()) {
            return;
        }

        $this->view->title = 'Керування об’єктами';
        $this->view->filters = $this->propertyMediaService()->adminFilters((array) $this->request->getQuery());
        $this->view->types = [];
        $this->view->locations = [];
        $this->view->properties = [];
        $this->view->stats = [];
        $this->view->pageStatus = null;
        $this->view->actionStatus = (string) $this->request->getQuery('status_message', 'string', '');

        try {
            $this->view->types = $this->catalogService()->propertyTypes();
            $this->view->locations = $this->catalogService()->locations();
            $this->view->properties = $this->propertyMediaService()->adminProperties($this->view->filters);
            $this->view->stats = $this->propertyMediaService()->adminStats();
        } catch (Throwable $e) {
            $this->logFrontendError('property-manage-page', $e);
            $this->response->setStatusCode(503, 'Service Unavailable');
            $this->view->pageStatus = 'Реєстр об’єктів тимчасово недоступний.';
        }
    }

    public function mapAction(): void
    {
        $this->view->title = 'Карта об’єктів';
        $this->view->metaTitle = 'Карта об’єктів | Terra Nova CLUB';
        $this->view->metaDescription = 'Карта об’єктів Terra Nova CLUB із фільтрами за типом, містом і бюджетом.';
        $this->loadPropertyWorkspace('map-page');
    }

    public function compareAction(): void
    {
        $this->view->title = 'Порівняння об’єктів';
        $this->view->metaTitle = 'Порівняння об’єктів | Terra Nova CLUB';
        $this->view->metaDescription = 'Порівняйте вибрані об’єкти Terra Nova CLUB за ціною, площею, містом і параметрами.';
        $this->loadPropertyWorkspace('compare-page');
    }

    public function typeAction(?string $code = null): void
    {
        $code = $code ?: (string) $this->dispatcher->getParam('params');
        $this->view->pick('property/seo');
        $this->view->seoKicker = 'Категорія';
        $this->view->seoTitle = 'Об’єкти категорії';
        $this->view->seoDescription = 'Добірка об’єктів Terra Nova за типом нерухомості з переходом у картку, Listing або подачу нового об’єкта.';
        $this->view->metaTitle = 'Об’єкти категорії | Terra Nova CLUB';
        $this->loadPropertyWorkspace('type-page', ['type' => $code]);
    }

    public function cityAction(?string $slug = null): void
    {
        $slug = $slug ?: (string) $this->dispatcher->getParam('params');
        $this->view->pick('property/seo');
        $this->view->seoKicker = 'Місто';
        $this->view->seoTitle = 'Об’єкти у місті';
        $this->view->seoDescription = 'Міська сторінка каталогу Terra Nova для локального SEO, підбору об’єктів і майбутніх MLS-фільтрів.';
        $this->view->metaTitle = 'Об’єкти у місті | Terra Nova CLUB';
        $this->loadPropertyWorkspace('city-page', ['location' => $slug]);
    }

    public function submissionsAction(): void
    {
        if (!$this->requireManager()) {
            return;
        }

        $status = (string) $this->request->getQuery('status', 'string', '');
        $this->view->title = 'Модерація об’єктів';
        $this->view->status = $status;
        $this->view->submissions = [];
        $this->view->counts = [];
        $this->view->pageStatus = null;

        try {
            $this->view->submissions = $this->propertyModerationService()->submissions($status);
            $this->view->counts = $this->propertyModerationService()->counts();
        } catch (Throwable $e) {
            $this->logFrontendError('submissions-page', $e);
            $this->response->setStatusCode(503, 'Service Unavailable');
            $this->view->pageStatus = 'Заявки тимчасово недоступні. Спробуйте оновити сторінку трохи пізніше.';
        }
    }

    public function submissionAction(?string $id = null): void
    {
        if (!$this->requireManager()) {
            return;
        }

        $id = (int) ($id ?: $this->dispatcher->getParam('params') ?: $this->dispatcher->getParam('id'));
        $this->view->title = 'Заявка на об’єкт';
        $this->view->submission = null;
        $this->view->media = [];
        $this->view->actionStatus = null;
        $this->view->pageStatus = null;

        try {
            $this->view->submission = $this->propertyModerationService()->submission($id);

            if (!$this->view->submission) {
                $this->response->setStatusCode(404, 'Not Found');
                return;
            }

            $this->view->media = $this->propertyModerationService()->submissionMedia($id);
        } catch (Throwable $e) {
            $this->logFrontendError('submission-page', $e);
            $this->response->setStatusCode(503, 'Service Unavailable');
            $this->view->pageStatus = 'Заявку тимчасово не вдалося відкрити.';
        }
    }

    public function moderateAction(?string $id = null): void
    {
        if (!$this->requireManager()) {
            return;
        }

        $id = (int) ($id ?: $this->dispatcher->getParam('params') ?: $this->dispatcher->getParam('id'));
        $action = (string) $this->request->getPost('moderation_action', 'string', '');
        $note = (string) $this->request->getPost('review_note', 'string', '');

        if (!$this->request->isPost() || $id <= 0) {
            $this->response->redirect('property/submissions');
            return;
        }

        $result = $this->propertyModerationService()->moderate($id, $action, $note);
        $target = $result['slug'] ?? null
            ? 'property/show/' . $result['slug']
            : 'property/submission/' . $id . '?status=' . rawurlencode($result['message']);

        $this->response->redirect($target);
    }

    public function editAction(?string $id = null): void
    {
        if (!$this->requireManager()) {
            return;
        }

        $propertyId = (int) ($id ?: $this->dispatcher->getParam('params') ?: $this->dispatcher->getParam('id'));
        $this->view->title = 'Редагувати медіа об’єкта';
        $this->view->property = null;
        $this->view->images = [];
        $this->view->types = [];
        $this->view->locations = [];
        $this->view->pageStatus = null;
        $this->view->actionStatus = (string) $this->request->getQuery('status', 'string', '');

        try {
            $property = $this->propertyMediaService()->property($propertyId);

            if (!$property) {
                $this->response->setStatusCode(404, 'Not Found');
                return;
            }

            $this->view->property = $property;
            $this->view->images = $this->propertyMediaService()->images($propertyId);
            $this->view->types = $this->catalogService()->propertyTypes();
            $this->view->locations = $this->catalogService()->locations();
        } catch (Throwable $e) {
            $this->logFrontendError('property-media-edit', $e);
            $this->response->setStatusCode(503, 'Service Unavailable');
            $this->view->pageStatus = 'Медіа об’єкта тимчасово недоступні.';
        }
    }

    public function updateAction(?string $id = null): void
    {
        if (!$this->requireManager()) {
            return;
        }

        $propertyId = (int) ($id ?: $this->dispatcher->getParam('params') ?: $this->dispatcher->getParam('id'));

        if (!$this->request->isPost() || $propertyId <= 0) {
            $this->response->redirect('property/catalog');
            return;
        }

        $result = $this->propertyMediaService()->updateDetails(
            $propertyId,
            (array) $this->request->getPost()
        );

        $this->response->redirect('property/edit/' . $propertyId . '?status=' . rawurlencode($result['message']));
    }

    public function statusAction(?string $id = null): void
    {
        if (!$this->requireManager()) {
            return;
        }

        $propertyId = (int) ($id ?: $this->dispatcher->getParam('params') ?: $this->dispatcher->getParam('id'));

        if (!$this->request->isPost() || $propertyId <= 0) {
            $this->response->redirect('property/manage');
            return;
        }

        $result = $this->propertyMediaService()->updateStatus(
            $propertyId,
            (string) $this->request->getPost('status', 'string', '')
        );

        $returnUrl = (string) $this->request->getPost('return_url', 'string', '');
        $target = $returnUrl !== '' && str_starts_with($returnUrl, 'property/manage')
            ? $returnUrl
            : 'property/manage';
        $separator = str_contains($target, '?') ? '&' : '?';

        $this->response->redirect($target . $separator . 'status_message=' . rawurlencode($result['message']));
    }

    public function mediaAction(?string $id = null): void
    {
        if (!$this->requireManager()) {
            return;
        }

        $propertyId = (int) ($id ?: $this->dispatcher->getParam('params') ?: $this->dispatcher->getParam('id'));

        if (!$this->request->isPost() || $propertyId <= 0) {
            $this->response->redirect('property/catalog');
            return;
        }

        $result = $this->propertyMediaService()->update(
            $propertyId,
            (array) $this->request->getPost(),
            (array) $_FILES
        );

        $this->response->redirect('property/edit/' . $propertyId . '?status=' . rawurlencode($result['message']));
    }

    public function favourAction(): void
    {
        $this->view->title = 'Вибрані об’єкти';
        $this->view->metaTitle = 'Вибрані об’єкти | Terra Nova CLUB';
        $this->loadPropertyWorkspace('favour-page');
    }

    private function loadPropertyWorkspace(string $label, array $filterOverrides = []): void
    {
        $this->view->catalogStatus = null;
        $this->view->filters = array_merge(
            $this->catalogService()->filtersFromQuery((array) $this->request->getQuery()),
            $filterOverrides
        );
        $this->view->types = [];
        $this->view->locations = [];
        $this->view->properties = [];
        $this->view->resultCount = 0;
        $this->view->catalogStats = [];

        try {
            $this->view->types = $this->catalogService()->propertyTypes();
            $this->view->locations = $this->catalogService()->locations();
            $this->view->properties = $this->catalogService()->catalogProperties($this->view->filters);
            $this->view->resultCount = $this->catalogService()->catalogCount($this->view->filters);
            $this->view->catalogStats = $this->catalogService()->catalogStats($this->view->filters);
        } catch (Throwable $e) {
            $this->logFrontendError($label, $e);
            $this->response->setStatusCode(503, 'Service Unavailable');
            $this->view->catalogStatus = 'Дані об’єктів тимчасово недоступні. Ми вже знаємо про проблему й відновимо їх.';
        }
    }

    private function absoluteUrl(string $path): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8001';

        return $scheme . '://' . $host . '/' . ltrim($path, '/');
    }
}
