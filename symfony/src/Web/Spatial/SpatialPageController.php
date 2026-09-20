<?php
declare(strict_types=1);

namespace App\Web\Spatial;

use App\Web\Phtml\PhtmlRenderer;
use App\Web\WorkspacePageContext;
use Domains\Spatial\Application\Contract\SpatialSceneInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class SpatialPageController
{
    public function __construct(
        private PhtmlRenderer $renderer,
        private WorkspacePageContext $page,
        private SpatialSceneInterface $scenes,
    ) {
    }

    public function manage(Request $request): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;

        $filters = $request->query->all();
        $variables = array_replace(
            $this->page->variables($request, $tenant, 'Spatial / 3D', 'spatial', [], 'properties'),
            [
                'filters' => $filters,
                'scenes' => $this->scenes->managerScenes($filters),
                'stats' => $this->scenes->stats(),
                'pageStatus' => trim((string) $request->query->get('status_message', '')),
            ],
        );

        return $this->html($request, 'spatial/manage', $variables);
    }

    public function edit(Request $request, int $id = 0): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;

        $scene = $id > 0 ? $this->scenes->scene($id) : null;
        if ($id > 0 && $scene === null) {
            return new Response('Spatial scene not found.', 404);
        }

        $scene ??= [
            'id' => 0, 'title' => '', 'slug' => '', 'description' => '', 'scene_type' => 'model',
            'viewer_type' => 'threejs', 'provider' => 'native', 'status' => 'draft', 'external_url' => '',
            'property_id' => (int) $request->query->get('property_id', 0),
            'default_camera_json' => '', 'settings_json' => '', 'assets' => [],
            'hotspots' => [], 'versions' => [], 'captures' => [], 'jobs' => [],
        ];

        $variables = array_replace(
            $this->page->variables(
                $request,
                $tenant,
                ((string) ($scene['title'] ?? '') !== '' ? (string) $scene['title'] : 'Нова 3D-сцена'),
                'spatial',
                ['terranova-spatial-admin'],
                'properties',
            ),
            [
                'scene' => $scene,
                'properties' => $this->scenes->propertyOptions(),
                'actionStatus' => trim((string) $request->query->get('status_message', '')),
            ],
        );

        return $this->html($request, 'spatial/edit', $variables);
    }

    public function save(Request $request, int $id = 0): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;

        $input = $request->request->all();
        $input['id'] = $id > 0 ? $id : (int) ($input['id'] ?? 0);
        $result = $this->scenes->save($input, $this->actor($tenant));
        $target = !empty($result['id']) ? '/spatial/edit/' . (int) $result['id'] : '/spatial/edit';

        return $this->redirectStatus($target, (string) ($result['message'] ?? 'Spatial сцену збережено.'));
    }

    public function upload(Request $request, int $id): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;

        try {
            $file = $request->files->get('asset_file');
            $asset = $this->scenes->upload(
                $id,
                $this->uploadArray($file),
                $request->request->all(),
                $this->actor($tenant),
            );

            if ($this->wantsJson($request)) {
                return new JsonResponse([
                    'ok' => true,
                    'message' => 'Asset завантажено та поставлено в обробку.',
                    'asset' => $asset,
                ], 201);
            }

            return $this->redirectStatus('/spatial/edit/' . $id, 'Asset завантажено та поставлено в обробку.');
        } catch (Throwable $error) {
            if ($this->wantsJson($request)) {
                return new JsonResponse(['ok' => false, 'message' => $error->getMessage()], 422);
            }

            return $this->redirectStatus('/spatial/edit/' . $id, $error->getMessage());
        }
    }

    public function external(Request $request, int $id): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;

        try {
            $this->scenes->externalAsset($id, $request->request->all());
            $message = 'Зовнішній asset підключено.';
        } catch (Throwable $error) {
            $message = $error->getMessage();
        }

        return $this->redirectStatus('/spatial/edit/' . $id, $message);
    }

    public function capture(Request $request, int $id): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;

        try {
            $this->scenes->capture($id, $request->request->all(), $this->actor($tenant));
            $message = 'Нову версію capture зареєстровано.';
        } catch (Throwable $error) {
            $message = $error->getMessage();
        }

        return $this->redirectStatus('/spatial/edit/' . $id, $message);
    }

    public function hotspot(Request $request, int $id): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;

        try {
            $this->scenes->saveHotspot($id, $request->request->all());
            $message = 'Hotspot збережено.';
        } catch (Throwable $error) {
            $message = $error->getMessage();
        }

        return $this->redirectStatus('/spatial/edit/' . $id, $message);
    }

    public function publish(int $id): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;

        $result = $this->scenes->publish($id);
        return $this->redirectStatus('/spatial/edit/' . $id, (string) ($result['message'] ?? 'Spatial сцену опубліковано.'));
    }

    public function scene(Request $request, string $slug): Response
    {
        $scene = $this->scenes->publicScene(trim($slug));
        if ($scene === null) {
            return new Response('Spatial scene not found.', 404);
        }

        $variables = [
            'scene' => $scene,
            'interfaceSurface' => 'public',
            'pageAssetEntries' => ['spatial-viewer'],
            'metaTitle' => (string) $scene['title'] . ' | 3D Terra Nova CLUB',
            'metaDescription' => (string) ($scene['description'] ?: 'Інтерактивна 3D-презентація об’єкта Terra Nova CLUB.'),
            'metaUrl' => rtrim($request->getSchemeAndHttpHost(), '/') . '/spatial/scene/' . rawurlencode((string) $scene['slug']),
        ];
        if (!empty($scene['viewer']['poster_url'])) {
            $variables['metaImage'] = (string) $scene['viewer']['poster_url'];
        }

        return $this->html($request, 'spatial/scene', $variables);
    }

    private function manager(): TenantContext|Response
    {
        $tenant = $this->page->current();
        if ($tenant === null) return new RedirectResponse('/auth/login');
        if (!$tenant->isManager()) return new Response('Forbidden', 403);
        return $tenant;
    }

    /** @return array{id:int,organization_id:string,role:string} */
    private function actor(TenantContext $tenant): array
    {
        return [
            'id' => (int) $tenant->userId()->value(),
            'organization_id' => $tenant->organizationId()->value(),
            'role' => $tenant->role()->value(),
        ];
    }

    /** @return array{name:string,type:string,tmp_name:string,error:int,size:int} */
    private function uploadArray(mixed $file): array
    {
        if (!$file instanceof UploadedFile) {
            return ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0];
        }

        return [
            'name' => $file->getClientOriginalName(),
            'type' => $file->getClientMimeType(),
            'tmp_name' => $file->getPathname(),
            'error' => $file->getError(),
            'size' => $file->getSize() ?: 0,
        ];
    }

    /** @param array<string,mixed> $variables */
    private function html(Request $request, string $view, array $variables, int $status = 200): Response
    {
        return new Response(
            $this->renderer->render($request, $view, $variables),
            $status,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    private function redirectStatus(string $target, string $message): RedirectResponse
    {
        return new RedirectResponse($target . '?status_message=' . rawurlencode($message));
    }

    private function wantsJson(Request $request): bool
    {
        return str_contains(strtolower((string) $request->headers->get('Accept', '')), 'application/json')
            || strtolower((string) $request->headers->get('X-Requested-With', '')) === 'xmlhttprequest';
    }
}
