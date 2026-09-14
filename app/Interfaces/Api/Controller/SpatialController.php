<?php
declare(strict_types=1);

namespace Interfaces\Api\Controller;

use Domains\Spatial\Application\Contract\SpatialAccessInterface;
use Domains\Spatial\Application\Contract\SpatialProcessingInterface;
use Domains\Spatial\Application\Contract\SpatialSceneInterface;
use Phalcon\Http\ResponseInterface;
use Phalcon\Mvc\Controller;
use Throwable;

class SpatialController extends Controller
{
    public function tokenAction(): ResponseInterface
    {
        $result = $this->access()->token($this->input());
        return $this->json($result, (int) ($result['status'] ?? 200));
    }

    public function sceneAction(?string $publicId = null): ResponseInterface
    {
        $reference = $publicId ?: (string) $this->dispatcher->getParam('publicId');
        $scene = $this->scenes()->publicScene($reference);
        return $scene ? $this->json(['ok' => true, 'scene' => $scene]) : $this->json(['ok' => false, 'message' => 'Scene not found.'], 404);
    }

    public function saveSceneAction(): ResponseInterface
    {
        $user = $this->editor();
        if (!$user) {
            return $this->json(['ok' => false, 'message' => 'Unauthorized.'], 401);
        }
        return $this->json($this->scenes()->save($this->input(), $user));
    }

    public function uploadAssetAction(?string $id = null): ResponseInterface
    {
        $user = $this->editor();
        if (!$user) {
            return $this->json(['ok' => false, 'message' => 'Unauthorized.'], 401);
        }
        try {
            $asset = $this->scenes()->upload((int) ($id ?: $this->dispatcher->getParam('id')), $_FILES['file'] ?? [], (array) $this->request->getPost(), $user);
            return $this->json(['ok' => true, 'asset' => $asset], 201);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function externalAssetAction(?string $id = null): ResponseInterface
    {
        if (!$this->editor()) {
            return $this->json(['ok' => false, 'message' => 'Unauthorized.'], 401);
        }
        try {
            return $this->json(['ok' => true, 'asset' => $this->scenes()->externalAsset((int) ($id ?: $this->dispatcher->getParam('id')), $this->input())], 201);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function captureAction(?string $id = null): ResponseInterface
    {
        $user = $this->editor();
        if (!$user) {
            return $this->json(['ok' => false, 'message' => 'Unauthorized.'], 401);
        }
        try {
            return $this->json(['ok' => true, 'capture' => $this->scenes()->capture((int) ($id ?: $this->dispatcher->getParam('id')), $this->input(), $user)], 201);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function saveHotspotAction(?string $id = null): ResponseInterface
    {
        if (!$this->editor()) {
            return $this->json(['ok' => false, 'message' => 'Unauthorized.'], 401);
        }
        try {
            return $this->json(['ok' => true, 'hotspot' => $this->scenes()->saveHotspot((int) ($id ?: $this->dispatcher->getParam('id')), $this->input())]);
        } catch (Throwable $e) {
            return $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function publishAction(?string $id = null): ResponseInterface
    {
        if (!$this->editor()) {
            return $this->json(['ok' => false, 'message' => 'Unauthorized.'], 401);
        }
        $result = $this->scenes()->publish((int) ($id ?: $this->dispatcher->getParam('id')));
        return $this->json($result, !empty($result['ok']) ? 200 : 422);
    }

    public function jobAction(?string $publicId = null): ResponseInterface
    {
        if (!$this->editor()) {
            return $this->json(['ok' => false, 'message' => 'Unauthorized.'], 401);
        }
        $job = $this->processor()->job($publicId ?: (string) $this->dispatcher->getParam('publicId'));
        return $job ? $this->json(['ok' => true, 'job' => $job]) : $this->json(['ok' => false, 'message' => 'Job not found.'], 404);
    }

    public function eventAction(): ResponseInterface
    {
        $input = $this->input();
        $ok = $this->scenes()->recordEvent((string) ($input['scene'] ?? ''), $input, $this->access()->actor($this->authorization()));
        return $this->json(['ok' => $ok], $ok ? 202 : 404);
    }

    private function input(): array
    {
        $contentType = mb_strtolower((string) $this->request->getHeader('Content-Type'));
        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode((string) $this->request->getRawBody(), true);
            return is_array($decoded) && !array_is_list($decoded) ? $decoded : [];
        }
        return (array) $this->request->getPost();
    }

    private function editor(): ?array
    {
        return $this->access()->editor($this->authorization());
    }

    private function authorization(): string
    {
        return (string) $this->request->getHeader('Authorization');
    }

    private function access(): SpatialAccessInterface
    {
        return $this->di->getShared('spatialAccessService');
    }

    private function scenes(): SpatialSceneInterface
    {
        return $this->di->getShared('spatialSceneService');
    }

    private function processor(): SpatialProcessingInterface
    {
        return $this->di->getShared('spatialProcessingService');
    }

    private function json(array $payload, int $status = 200): ResponseInterface
    {
        $this->view->disable();
        $this->response->setStatusCode($status);
        $this->response->setContentType('application/json', 'UTF-8');
        $this->response->setHeader('X-Content-Type-Options', 'nosniff');
        $this->response->setContent(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $this->response;
    }
}
