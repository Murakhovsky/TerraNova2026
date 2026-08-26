<?php
declare(strict_types=1);

namespace Modules\Frontend\Controllers;

use Throwable;

class ContentController extends ControllerBase
{
    public function manageAction(): void
    {
        if (!$this->requireManager()) {
            return;
        }
        $this->view->metaTitle = 'Контент і SEO | Terra Nova CLUB';
        $this->view->metaRobots = 'noindex,nofollow';
        $this->view->items = [];
        $this->view->stats = [];
        $this->view->integrationStats = [];
        $this->view->deliveries = [];
        $this->view->filters = (array) $this->request->getQuery();
        $this->view->pageStatus = null;
        try {
            $this->view->items = $this->contentService()->adminItems($this->view->filters);
            $this->view->stats = $this->contentService()->stats();
            $this->view->integrationStats = $this->contentService()->integrationStats();
            $this->view->deliveries = $this->contentService()->recentWebhookDeliveries();
        } catch (Throwable $e) {
            $this->logFrontendError('content-manage', $e);
            $this->response->setStatusCode(503, 'Service Unavailable');
            $this->view->pageStatus = 'Контент тимчасово недоступний. Деталі записано в лог.';
        }
    }

    public function editAction(?string $id = null): void
    {
        if (!$this->requireManager()) {
            return;
        }
        $contentId = (int) ($id ?: $this->dispatcher->getParam('id') ?: $this->dispatcher->getParam('params'));
        $item = $contentId > 0 ? $this->contentService()->item($contentId) : null;
        if ($contentId > 0 && !$item) {
            $this->response->setStatusCode(404, 'Not Found');
        }
        $this->view->metaTitle = ($item ? 'Редагування контенту' : 'Новий матеріал') . ' | Terra Nova CLUB';
        $this->view->metaRobots = 'noindex,nofollow';
        $this->view->item = $item ?: [
            'id' => 0,
            'content_type' => (string) $this->request->getQuery('type', 'string', 'blog_post'),
            'status' => 'draft',
            'robots' => 'index,follow',
            'body_html' => '',
        ];
        $this->view->revisions = $contentId > 0 ? $this->contentService()->revisions($contentId) : [];
        $this->view->actionStatus = (string) $this->request->getQuery('status_message', 'string', '');
    }

    public function saveAction(?string $id = null): void
    {
        $user = $this->requireManager();
        if (!$user || !$this->request->isPost()) {
            $this->response->redirect('admin/content');
            return;
        }
        $input = (array) $this->request->getPost();
        $routeId = $id ?: $this->dispatcher->getParam('id');
        $input['id'] = (int) ($routeId ?: ($input['id'] ?? 0));
        $result = $this->contentService()->save($input, $user);
        $target = !empty($result['id']) ? 'admin/content/edit/' . (int) $result['id'] : 'admin/content/edit';
        $this->response->redirect($target . '?status_message=' . rawurlencode((string) $result['message']));
    }
}
