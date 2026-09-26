<?php
declare(strict_types=1);

namespace App\Web\Spatial;

use App\Web\Navigation\NavigationBuilder;
use App\Web\Phtml\PhtmlRenderer;
use Domains\Spatial\Application\Contract\SpatialSceneInterface;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
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
        private TenantContextProviderInterface $tenants,
        private NavigationBuilder $navigation,
        private SpatialSceneInterface $scenes,
    ) {
    }

    public function edit(Request $request, ?string $id = null): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) {
            return $tenant;
        }

        $sceneId = max(0, (int) ($id ?? 0));
        $scene = $sceneId > 0 ? $this->scenes->scene($sceneId) : null;
        if ($sceneId > 0 && $scene === null) {
            return new Response('Spatial scene was not found.', 404);
        }

        $scene ??= [
            'id' => 0, 'title' => '', 'slug' => '', 'description' => '', 'scene_type' => 'model',
            'viewer_type' => 'threejs', 'provider' => 'native', 'status' => 'draft', 'external_url' => '',
            'property_id' => max(0, (int) $request->query->get('property_id', 0)),
            'default_camera_json' => '', 'settings_json' => '', 'assets' => [], 'hotspots' => [],
            'versions' => [], 'captures' => [], 'jobs' => [],
        ];

        return $this->workspace($request, $tenant, 'spatial/edit', [
            'metaTitle' => ((string) ($scene['title'] ?: 'Нова 3D-сцена')) . ' | Terra Nova CLUB',
            'metaRobots' => 'noindex,nofollow',
            'pageAssetEntries' => ['terranova-spatial-admin'],
            'scene' => $scene,
            'properties' => $this->scenes->propertyOptions(),
            'actionStatus' => trim((string) $request->query->get('status_message', '')),
        ]);
    }

    public function save(Request $request, ?string $id = null): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) {
            return $tenant;
        }

        $input = $request->request->all();
        $input['id'] = max(0, (int) ($id ?? ($input['id'] ?? 0)));
        $result = $this->scenes->save($input, $this->actor($tenant));
        $target = !empty($result['id']) ? '/spatial/edit/' . (int) $result['id'] : '/spatial/edit';

        return $this->redirectStatus($target, (string) ($result['message'] ?? 'Spatial scene updated.'));
    }

    public function upload(Request $request, string $id): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) {
            return $tenant;
        }

        $sceneId = (int) $id;
        try {
            $asset = $this->scenes->upload(
                $sceneId,
                $this->uploadFile($request->files->get('asset_file')),
                $request->request->all(),
                $this->actor($tenant),
            );

            if ($this->wantsJson($request)) {
                return new JsonResponse(['ok' => true, 'message' => 'Asset завантажено та поставлено в обробку.', 'asset' => $asset], 201);
            }

            return $this->redirectStatus('/spatial/edit/' . $sceneId, 'Asset завантажено та поставлено в обробку.');
        } catch (Throwable $error) {
            if ($this->wantsJson($request)) {
                return new JsonResponse(['ok' => false, 'message' => $error->getMessage()], 422);
            }

            return $this->redirectStatus('/spatial/edit/' . $sceneId, $error->getMessage());
        }
    }

    public function external(Request $request, string $id): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;

        try {
            $this->scenes->externalAsset((int) $id, $request->request->all());
            $message = 'Зовнішній asset підключено.';
        } catch (Throwable $error) {
            $message = $error->getMessage();
        }

        return $this->redirectStatus('/spatial/edit/' . (int) $id, $message);
    }

    public function capture(Request $request, string $id): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;

        try {
            $this->scenes->capture((int) $id, $request->request->all(), $this->actor($tenant));
            $message = 'Нову версію capture зареєстровано.';
        } catch (Throwable $error) {
            $message = $error->getMessage();
        }

        return $this->redirectStatus('/spatial/edit/' . (int) $id, $message);
    }

    public function hotspot(Request $request, string $id): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;

        try {
            $this->scenes->saveHotspot((int) $id, $request->request->all());
            $message = 'Hotspot збережено.';
        } catch (Throwable $error) {
            $message = $error->getMessage();
        }

        return $this->redirectStatus('/spatial/edit/' . (int) $id, $message);
    }

    public function publish(string $id): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;

        $result = $this->scenes->publish((int) $id);
        return $this->redirectStatus('/spatial/edit/' . (int) $id, (string) ($result['message'] ?? 'Spatial scene updated.'));
    }

    public function scene(Request $request, string $slug): Response
    {
        $scene = $this->scenes->publicScene($slug);
        if ($scene === null) {
            return new Response('Spatial scene was not found.', 404);
        }

        $variables = [
            'interfaceSurface' => 'public',
            'pageAssetEntries' => ['spatial-viewer'],
            'scene' => $scene,
            'metaTitle' => (string) $scene['title'] . ' | 3D Terra Nova CLUB',
            'metaDescription' => (string) ($scene['description'] ?: 'Інтерактивна 3D-презентація об’єкта Terra Nova CLUB.'),
            'metaUrl' => $request->getSchemeAndHttpHost() . '/spatial/scene/' . rawurlencode((string) $scene['slug']),
        ];
        if (!empty($scene['viewer']['poster_url'])) {
            $variables['metaImage'] = $scene['viewer']['poster_url'];
        }

        return new Response(
            $this->renderer->render($request, 'spatial/scene', $variables),
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    private function manager(): TenantContext|Response
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) {
            return new RedirectResponse('/auth/login');
        }
        if (!$tenant->isManager()) {
            return new Response('Forbidden', 403);
        }

        return $tenant;
    }

    /** @param array<string,mixed> $extra */
    private function workspace(Request $request, TenantContext $tenant, string $view, array $extra): Response
    {
        $role = $tenant->role()->value();
        $variables = array_replace([
            'workspaceSection' => 'properties',
            'workspaceActive' => 'spatial',
            'workspaceActiveSection' => 'properties',
            'currentUser' => ['id' => (int) $tenant->userId()->value(), 'role' => $role],
            'role' => $role,
            'isTeam' => true,
            'isAdmin' => $tenant->isAdmin(),
            'workspaceNavigation' => $this->navigation->workspace($tenant),
        ], $extra);

        return new Response(
            $this->renderer->render($request, $view, $variables),
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    /** @return array{id:int,organization_id:string,role:string,email:string,full_name:string} */
    private function actor(TenantContext $tenant): array
    {
        return [
            'id' => (int) $tenant->userId()->value(),
            'organization_id' => $tenant->organizationId()->value(),
            'role' => $tenant->role()->value(),
            'email' => '',
            'full_name' => '',
        ];
    }

    /** @return array{name:string,type:string,tmp_name:string,error:int,size:int} */
    private function uploadFile(mixed $file): array
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

    private function wantsJson(Request $request): bool
    {
        return str_contains(strtolower((string) $request->headers->get('Accept', '')), 'application/json')
            || strtolower((string) $request->headers->get('X-Requested-With', '')) === 'xmlhttprequest';
    }

    private function redirectStatus(string $target, string $message): RedirectResponse
    {
        return new RedirectResponse($target . '?status_message=' . rawurlencode($message));
    }
}
