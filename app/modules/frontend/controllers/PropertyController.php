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
        $this->view->pagination = [];
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
            $this->view->resultCount = $this->catalogService()->catalogCount($this->view->filters);
            $this->view->pagination = $this->catalogService()->catalogPagination($this->view->filters, $this->view->resultCount);
            $this->view->filters['page'] = $this->view->pagination['page'];
            $this->view->filters['per_page'] = $this->view->pagination['per_page'];
            $this->view->properties = $this->catalogService()->catalogProperties($this->view->filters);
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
        $this->view->groupedProperties = [];
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

            $this->catalogService()->recordPropertyView((int) $property['id'], [
                'source_page' => $this->request->getURI(),
                'referer' => (string) ($_SERVER['HTTP_REFERER'] ?? ''),
                'utm_source' => (string) $this->request->getQuery('utm_source', 'string', ''),
                'utm_medium' => (string) $this->request->getQuery('utm_medium', 'string', ''),
                'utm_campaign' => (string) $this->request->getQuery('utm_campaign', 'string', ''),
            ]);

            $this->view->property = $property;
            $this->view->images = $this->catalogService()->propertyImages((int) $property['id']);
            $this->view->features = $this->catalogService()->propertyFeatures((int) $property['id']);
            $this->view->groupedProperties = $this->catalogService()->groupedProperties($property);
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

    public function presentationAction(?string $slug = null): void
    {
        $slug = $slug ?: (string) $this->dispatcher->getParam('params');
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
                $this->catalogService()->recordPropertyView((int) $property['id'], [
                    'source_page' => $this->request->getURI(),
                    'referer' => (string) ($_SERVER['HTTP_REFERER'] ?? ''),
                    'utm_source' => (string) $this->request->getQuery('utm_source', 'string', ''),
                    'utm_medium' => (string) $this->request->getQuery('utm_medium', 'string', ''),
                    'utm_campaign' => (string) $this->request->getQuery('utm_campaign', 'string', ''),
                ]);

                $this->view->property = $property;
                $this->view->images = $this->catalogService()->propertyImages((int) $property['id']);
                $this->view->features = $this->catalogService()->propertyFeatures((int) $property['id']);
                $this->view->relatedProperties = $this->catalogService()->relatedProperties($property);
                $this->view->metaTitle = ($property['meta_title'] ?: $property['title']) . ' | Презентація Terra Nova CLUB';
                $this->view->metaDescription = $property['meta_description'] ?: ($property['short_description'] ?: 'Коротка презентація об’єкта Terra Nova CLUB з фото, ціною, параметрами та запитом.');
                $this->view->metaImage = $this->absoluteUrl((string) ($this->view->images[0]['image_url'] ?? 'img/terra-nova-og.jpg'));

                return;
            }

            $group = $this->catalogService()->propertyGroupBySlug($slug);
            if (!$group) {
                $this->response->setStatusCode(404, 'Not Found');
                return;
            }

            $properties = $this->catalogService()->propertyGroupPresentationProperties((int) $group['id']);
            $this->view->group = $group;
            $this->view->properties = $properties;
            $this->view->metaTitle = $group['title'] . ' | Презентація Terra Nova CLUB';
            $this->view->metaDescription = $group['description'] ?: 'Добірка опублікованих об’єктів за однією адресою або в одному проєкті.';
        } catch (Throwable $e) {
            $this->logFrontendError('property-presentation-page', $e);
            $this->response->setStatusCode(503, 'Service Unavailable');
            $this->view->pageStatus = 'Презентація тимчасово недоступна. Спробуйте оновити сторінку трохи пізніше.';
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
        $this->view->title = 'Внутрішній MLS / Listing';
        $this->view->metaTitle = 'Внутрішній MLS / Listing | Terra Nova CLUB';
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
        $this->view->propertyGroups = [];
        $this->view->agents = [];
        $this->view->properties = [];
        $this->view->stats = [];
        $this->view->qualityStats = [];
        $this->view->operationalStageRules = [];
        $this->view->pageStatus = null;
        $this->view->actionStatus = (string) $this->request->getQuery('status_message', 'string', '');

        try {
            $this->view->operationalStageRules = $this->propertyMediaService()->operationalStageRules();
            $this->view->types = $this->catalogService()->propertyTypes();
            $this->view->locations = $this->catalogService()->locations();
            $this->view->propertyGroups = $this->propertyMediaService()->propertyGroups();
            $this->view->agents = $this->propertyMediaService()->agents();
            $this->view->properties = $this->propertyMediaService()->adminProperties($this->view->filters);
            $this->view->stats = $this->propertyMediaService()->adminStats();
            $this->view->qualityStats = $this->propertyMediaService()->adminQualityStats($this->view->filters);
        } catch (Throwable $e) {
            $this->logFrontendError('property-manage-page', $e);
            $this->response->setStatusCode(503, 'Service Unavailable');
            $this->view->pageStatus = 'Реєстр об’єктів тимчасово недоступний.';
        }
    }

    public function addAction(): void
    {
        $user = $this->requireManager();
        if (!$user) {
            return;
        }

        $this->view->title = 'Додати об’єкт';
        $this->view->types = [];
        $this->view->locations = [];
        $this->view->propertyGroups = [];
        $this->view->agents = [];
        $this->view->operationalStageRules = [];
        $this->view->formData = (array) $this->request->getPost();
        $this->view->pageStatus = null;
        $this->view->actionStatus = null;

        try {
            $this->view->operationalStageRules = $this->propertyMediaService()->operationalStageRules();
            $this->view->types = $this->catalogService()->propertyTypes();
            $this->view->locations = $this->catalogService()->locations();
            $this->view->propertyGroups = $this->propertyMediaService()->propertyGroups();
            $this->view->agents = $this->propertyMediaService()->agents();

            if ($this->request->isPost()) {
                if ($this->isOversizedPost()) {
                    $this->view->actionStatus = 'Файли завеликі для поточних налаштувань сервера. Максимальний пакет: ' . $this->bytesLabel($this->iniBytes('post_max_size')) . '.';
                    return;
                }

                $result = $this->propertyMediaService()->createDraft(
                    (array) $this->request->getPost(),
                    (int) ($user['id'] ?? 0),
                    (array) $_FILES
                );

                if ($result['ok'] ?? false) {
                    $this->response->redirect('property/edit/' . (int) $result['property_id'] . '?status=' . rawurlencode((string) $result['message']));
                    return;
                }

                $this->view->actionStatus = (string) ($result['message'] ?? 'Об’єкт не вдалося створити.');
            }
        } catch (Throwable $e) {
            $this->logFrontendError('property-add-page', $e);
            $this->response->setStatusCode(503, 'Service Unavailable');
            $this->view->pageStatus = 'Форма створення об’єкта тимчасово недоступна.';
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
        $this->view->seoDescription = 'Добірка об’єктів Terra Nova за типом нерухомості з переходом у картку, запит або подачу нового об’єкта.';
        $this->view->metaTitle = 'Об’єкти категорії | Terra Nova CLUB';
        $this->loadPropertyWorkspace('type-page', ['type' => $code]);
    }

    public function cityAction(?string $slug = null): void
    {
        $slug = $slug ?: (string) $this->dispatcher->getParam('params');
        $this->view->pick('property/seo');
        $this->view->seoKicker = 'Місто';
        $this->view->seoTitle = 'Об’єкти у місті';
        $this->view->seoDescription = 'Міська сторінка каталогу Terra Nova для локальної добірки, фільтрів і підбору об’єктів.';
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

    public function groupAction(?string $id = null): void
    {
        if (!$this->requireManager()) {
            return;
        }

        $groupId = (int) ($id ?: $this->dispatcher->getParam('params') ?: $this->dispatcher->getParam('id'));
        $this->view->title = 'Група об’єктів';
        $this->view->group = null;
        $this->view->properties = [];
        $this->view->locations = [];
        $this->view->pageStatus = null;
        $this->view->actionStatus = (string) $this->request->getQuery('status', 'string', '');

        if ($groupId <= 0) {
            $this->response->redirect('property/manage');
            return;
        }

        try {
            if ($this->request->isPost()) {
                $result = $this->propertyMediaService()->updatePropertyGroup(
                    $groupId,
                    (array) $this->request->getPost()
                );

                $this->response->redirect('property/group/' . $groupId . '?status=' . rawurlencode((string) ($result['message'] ?? '')));
                return;
            }

            $group = $this->propertyMediaService()->propertyGroup($groupId);
            if (!$group) {
                $this->response->setStatusCode(404, 'Not Found');
                return;
            }

            $this->view->group = $group;
            $this->view->properties = $this->propertyMediaService()->propertyGroupProperties($groupId);
            $this->view->locations = $this->catalogService()->locations();
        } catch (Throwable $e) {
            $this->logFrontendError('property-group-page', $e);
            $this->response->setStatusCode(503, 'Service Unavailable');
            $this->view->pageStatus = 'Група об’єктів тимчасово недоступна.';
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
        $this->view->propertyGroups = [];
        $this->view->agents = [];
        $this->view->activities = [];
        $this->view->inboundRequests = [];
        $this->view->caseMatches = [];
        $this->view->readiness = [];
        $this->view->operationalStageRules = [];
        $this->view->operationalStageCheck = [];
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
            $this->view->readiness = $this->propertyMediaService()->readiness($propertyId);
            $this->view->operationalStageRules = $this->propertyMediaService()->operationalStageRules();
            $this->view->operationalStageCheck = $this->propertyMediaService()->operationalStageCheck($propertyId);
            $this->view->types = $this->catalogService()->propertyTypes();
            $this->view->locations = $this->catalogService()->locations();
            $this->view->propertyGroups = $this->propertyMediaService()->propertyGroups();
            $this->view->agents = $this->propertyMediaService()->agents();
            $this->view->activities = $this->propertyMediaService()->activities($propertyId);
            $this->view->inboundRequests = $this->propertyMediaService()->inboundRequests($propertyId);
            $this->view->caseMatches = $this->propertyMediaService()->caseMatches($propertyId);
        } catch (Throwable $e) {
            $this->logFrontendError('property-media-edit', $e);
            $this->response->setStatusCode(503, 'Service Unavailable');
            $this->view->pageStatus = 'Медіа об’єкта тимчасово недоступні.';
        }
    }

    public function updateAction(?string $id = null): void
    {
        $user = $this->requireManager();
        if (!$user) {
            return;
        }

        $propertyId = (int) ($id ?: $this->dispatcher->getParam('params') ?: $this->dispatcher->getParam('id'));

        if (!$this->request->isPost() || $propertyId <= 0) {
            $this->response->redirect('property/catalog');
            return;
        }

        $result = $this->propertyMediaService()->updateDetails(
            $propertyId,
            (array) $this->request->getPost(),
            (int) ($user['id'] ?? 0)
        );

        $this->response->redirect('property/edit/' . $propertyId . '?status=' . rawurlencode($result['message']));
    }

    public function statusAction(?string $id = null): void
    {
        $user = $this->requireManager();
        if (!$user) {
            return;
        }

        $propertyId = (int) ($id ?: $this->dispatcher->getParam('params') ?: $this->dispatcher->getParam('id'));

        if (!$this->request->isPost() || $propertyId <= 0) {
            $this->response->redirect('property/manage');
            return;
        }

        $result = $this->propertyMediaService()->updateStatus(
            $propertyId,
            (string) $this->request->getPost('status', 'string', ''),
            (string) $this->request->getPost('status_note', 'string', ''),
            (int) ($user['id'] ?? 0)
        );

        $returnUrl = (string) $this->request->getPost('return_url', 'string', '');
        $target = $returnUrl !== '' && str_starts_with($returnUrl, 'property/manage')
            ? $returnUrl
            : 'property/manage';
        $separator = str_contains($target, '?') ? '&' : '?';

        $this->response->redirect($target . $separator . 'status_message=' . rawurlencode($result['message']));
    }

    public function quickAction(?string $id = null): void
    {
        $user = $this->requireManager();
        if (!$user) {
            return;
        }

        $propertyId = (int) ($id ?: $this->dispatcher->getParam('params') ?: $this->dispatcher->getParam('id'));

        if (!$this->request->isPost() || $propertyId <= 0) {
            $this->response->redirect('property/manage');
            return;
        }

        $result = $this->propertyMediaService()->quickAction(
            $propertyId,
            (string) $this->request->getPost('quick_action', 'string', ''),
            (array) $this->request->getPost(),
            (int) ($user['id'] ?? 0)
        );

        $this->response->redirect('property/edit/' . $propertyId . '?status=' . rawurlencode((string) $result['message']));
    }

    public function mediaAction(?string $id = null): void
    {
        $user = $this->requireManager();
        if (!$user) {
            return;
        }

        $propertyId = (int) ($id ?: $this->dispatcher->getParam('params') ?: $this->dispatcher->getParam('id'));

        if (!$this->request->isPost() || $propertyId <= 0) {
            $this->response->redirect('property/catalog');
            return;
        }

        if ($this->isOversizedPost()) {
            $this->response->redirect('property/edit/' . $propertyId . '?status=' . rawurlencode('Файли завеликі для поточних налаштувань сервера. Максимальний пакет: ' . $this->bytesLabel($this->iniBytes('post_max_size')) . '.'));
            return;
        }

        $result = $this->propertyMediaService()->update(
            $propertyId,
            (array) $this->request->getPost(),
            (array) $_FILES,
            (int) ($user['id'] ?? 0)
        );

        $this->response->redirect('property/edit/' . $propertyId . '?status=' . rawurlencode($result['message']));
    }

    public function noteAction(?string $id = null): void
    {
        $user = $this->requireManager();
        if (!$user) {
            return;
        }

        $propertyId = (int) ($id ?: $this->dispatcher->getParam('params') ?: $this->dispatcher->getParam('id'));

        if (!$this->request->isPost() || $propertyId <= 0) {
            $this->response->redirect('property/manage');
            return;
        }

        $result = $this->propertyMediaService()->addActivityNote(
            $propertyId,
            (array) $this->request->getPost(),
            (int) ($user['id'] ?? 0)
        );

        $this->response->redirect('property/edit/' . $propertyId . '?status=' . rawurlencode((string) $result['message']));
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
        $this->view->pagination = [];
        $this->view->catalogStats = [];

        try {
            $this->view->types = $this->catalogService()->propertyTypes();
            $this->view->locations = $this->catalogService()->locations();
            $this->view->resultCount = $this->catalogService()->catalogCount($this->view->filters);
            $this->view->pagination = $this->catalogService()->catalogPagination($this->view->filters, $this->view->resultCount);
            $this->view->filters['page'] = $this->view->pagination['page'];
            $this->view->filters['per_page'] = $this->view->pagination['per_page'];
            $this->view->properties = $this->catalogService()->catalogProperties($this->view->filters);
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

    private function isOversizedPost(): bool
    {
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        $limit = $this->iniBytes('post_max_size');

        return $contentLength > 0 && $limit > 0 && $contentLength > $limit;
    }

    private function iniBytes(string $key): int
    {
        $value = trim((string) ini_get($key));
        if ($value === '') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float) $value;

        return match ($unit) {
            'g' => (int) ($number * 1073741824),
            'm' => (int) ($number * 1048576),
            'k' => (int) ($number * 1024),
            default => (int) $number,
        };
    }

    private function bytesLabel(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return rtrim(rtrim(number_format($bytes / 1048576, 1, '.', ''), '0'), '.') . ' МБ';
        }

        return (string) $bytes . ' Б';
    }
}
