<?php
declare(strict_types=1);

namespace Interfaces\Web\Controller;

use Throwable;

class SpatialController extends ControllerBase
{
    public function manageAction(): void
    {
        if (!$this->requireManager()) {
            return;
        }
        $this->view->metaTitle = 'Spatial / 3D | Terra Nova CLUB';
        $this->view->metaRobots = 'noindex,nofollow';
        $this->view->filters = (array) $this->request->getQuery();
        $this->view->scenes = $this->spatialSceneService()->managerScenes($this->view->filters);
        $this->view->stats = $this->spatialSceneService()->stats();
        $this->view->pageStatus = (string) $this->request->getQuery('status_message', 'string', '');
    }

    public function editAction(?string $id = null): void
    {
        if (!$this->requireManager()) {
            return;
        }
        $sceneId = (int) ($id ?: $this->dispatcher->getParam('id'));
        $scene = $sceneId > 0 ? $this->spatialSceneService()->scene($sceneId) : null;
        if ($sceneId > 0 && !$scene) {
            $this->response->setStatusCode(404, 'Not Found');
        }
        $this->view->metaTitle = ($scene ? $scene['title'] : 'Нова 3D-сцена') . ' | Terra Nova CLUB';
        $this->view->metaRobots = 'noindex,nofollow';
        $this->view->pageAssetEntries = ['terranova-spatial-admin'];
        $this->view->scene = $scene ?: [
            'id' => 0, 'title' => '', 'slug' => '', 'description' => '', 'scene_type' => 'model',
            'viewer_type' => 'threejs', 'provider' => 'native', 'status' => 'draft', 'external_url' => '',
            'property_id' => (int) $this->request->getQuery('property_id', 'int', 0), 'default_camera_json' => '', 'settings_json' => '', 'assets' => [],
            'hotspots' => [], 'versions' => [], 'captures' => [], 'jobs' => [],
        ];
        $this->view->properties = $this->spatialSceneService()->propertyOptions();
        $this->view->actionStatus = (string) $this->request->getQuery('status_message', 'string', '');
    }

    public function saveAction(?string $id = null): void
    {
        $user = $this->requireManager();
        if (!$user || !$this->request->isPost()) {
            $this->response->redirect('spatial/manage');
            return;
        }
        $input = (array) $this->request->getPost();
        $routeId = $id ?: $this->dispatcher->getParam('id');
        $input['id'] = (int) ($routeId ?: ($input['id'] ?? 0));
        $result = $this->spatialSceneService()->save($input, $user);
        $target = !empty($result['id']) ? 'spatial/edit/' . (int) $result['id'] : 'spatial/edit';
        $this->redirectStatus($target, (string) $result['message']);
    }

    public function uploadAction(?string $id = null): void
    {
        $user = $this->requireManager();
        if (!$user || !$this->request->isPost()) {
            return;
        }
        $sceneId = (int) ($id ?: $this->dispatcher->getParam('id'));
        try {
            $asset = $this->spatialSceneService()->upload($sceneId, $_FILES['asset_file'] ?? [], (array) $this->request->getPost(), $user);
            if ($this->wantsJson()) {
                $this->json(['ok' => true, 'message' => 'Asset завантажено та поставлено в обробку.', 'asset' => $asset], 201);
                return;
            }
            $this->redirectStatus('spatial/edit/' . $sceneId, 'Asset завантажено та поставлено в обробку.');
        } catch (Throwable $e) {
            if ($this->wantsJson()) {
                $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
                return;
            }
            $this->redirectStatus('spatial/edit/' . $sceneId, $e->getMessage());
        }
    }

    public function externalAction(?string $id = null): void
    {
        if (!$this->requireManager() || !$this->request->isPost()) {
            return;
        }
        $sceneId = (int) ($id ?: $this->dispatcher->getParam('id'));
        try {
            $this->spatialSceneService()->externalAsset($sceneId, (array) $this->request->getPost());
            $message = 'Зовнішній asset підключено.';
        } catch (Throwable $e) {
            $message = $e->getMessage();
        }
        $this->redirectStatus('spatial/edit/' . $sceneId, $message);
    }

    public function captureAction(?string $id = null): void
    {
        $user = $this->requireManager();
        if (!$user || !$this->request->isPost()) {
            return;
        }
        $sceneId = (int) ($id ?: $this->dispatcher->getParam('id'));
        try {
            $this->spatialSceneService()->capture($sceneId, (array) $this->request->getPost(), $user);
            $message = 'Нову версію capture зареєстровано.';
        } catch (Throwable $e) {
            $message = $e->getMessage();
        }
        $this->redirectStatus('spatial/edit/' . $sceneId, $message);
    }

    public function hotspotAction(?string $id = null): void
    {
        if (!$this->requireManager() || !$this->request->isPost()) {
            return;
        }
        $sceneId = (int) ($id ?: $this->dispatcher->getParam('id'));
        try {
            $this->spatialSceneService()->saveHotspot($sceneId, (array) $this->request->getPost());
            $message = 'Hotspot збережено.';
        } catch (Throwable $e) {
            $message = $e->getMessage();
        }
        $this->redirectStatus('spatial/edit/' . $sceneId, $message);
    }

    public function publishAction(?string $id = null): void
    {
        if (!$this->requireManager() || !$this->request->isPost()) {
            return;
        }
        $sceneId = (int) ($id ?: $this->dispatcher->getParam('id'));
        $result = $this->spatialSceneService()->publish($sceneId);
        $this->redirectStatus('spatial/edit/' . $sceneId, (string) $result['message']);
    }

    public function sceneAction(?string $slug = null): void
    {
        $reference = $slug ?: (string) $this->dispatcher->getParam('slug');
        $scene = $this->spatialSceneService()->publicScene($reference);
        if (!$scene) {
            $this->response->setStatusCode(404, 'Not Found');
            return;
        }
        $this->view->scene = $scene;
        $this->view->pageAssetEntries = ['spatial-viewer'];
        $this->view->metaTitle = $scene['title'] . ' | 3D Terra Nova CLUB';
        $this->view->metaDescription = $scene['description'] ?: 'Інтерактивна 3D-презентація об’єкта Terra Nova CLUB.';
        if (!empty($scene['viewer']['poster_url'])) {
            $this->view->metaImage = $scene['viewer']['poster_url'];
        }
        $this->view->metaUrl = $this->absoluteUrl('spatial/scene/' . $scene['slug']);
    }

    private function redirectStatus(string $target, string $message): void
    {
        $this->response->redirect($target . '?status_message=' . rawurlencode($message));
    }

    private function wantsJson(): bool
    {
        return str_contains(mb_strtolower((string) $this->request->getHeader('Accept')), 'application/json')
            || mb_strtolower((string) $this->request->getHeader('X-Requested-With')) === 'xmlhttprequest';
    }

    private function absoluteUrl(string $path): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1:8001') . '/' . ltrim($path, '/');
    }
}

