<?php

declare(strict_types=1);

namespace App\Web\Spatial;

use App\Web\Experience\Archetype\PageArchetype;
use App\Web\Experience\Archetype\PagePresentationFactory;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Shell\ShellBreadcrumb;
use App\Web\Experience\Shell\WorkspaceShellFactory;
use App\Web\Phtml\ViteAssetManifest;
use Domains\Spatial\Application\Contract\SpatialSceneInterface;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twig\Environment;

final readonly class SpatialPageController
{
    public function __construct(
        private Environment $twig,
        private TenantContextProviderInterface $tenants,
        private WorkspaceShellFactory $shells,
        private PagePresentationFactory $pages,
        private SpatialSceneInterface $scenes,
        private ViteAssetManifest $vite,
    ) {
    }

    public function edit(Request $request, ?string $id = null): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;

        $sceneId = max(0, (int) ($id ?? 0));
        $scene = $sceneId > 0 ? $this->scenes->scene($sceneId) : null;
        if ($sceneId > 0 && $scene === null) {
            return new Response('Spatial scene was not found.', Response::HTTP_NOT_FOUND);
        }

        $scene ??= [
            'id' => 0, 'title' => '', 'slug' => '', 'description' => '', 'scene_type' => 'model',
            'viewer_type' => 'threejs', 'provider' => 'native', 'status' => 'draft', 'external_url' => '',
            'property_id' => max(0, (int) $request->query->get('property_id', 0)),
            'default_camera_json' => '', 'settings_json' => '', 'assets' => [], 'hotspots' => [],
            'versions' => [], 'captures' => [], 'jobs' => [],
        ];

        $context = new WebExtensionContext(
            organizationId: $tenant->organizationId()->value(),
            role: $tenant->role()->value(),
            surface: 'workspace',
            activeSection: 'properties',
            activeItem: 'spatial',
        );
        $shell = $this->shells->create($tenant, $context, 'Spatial Editor', [
            new ShellBreadcrumb('Workspace', '/admin'),
            new ShellBreadcrumb('Properties', '/property/manage'),
            new ShellBreadcrumb('Spatial', '/spatial/manage'),
            new ShellBreadcrumb($sceneId > 0 ? (string) ($scene['title'] ?? 'Scene') : 'New scene'),
        ]);

        return new Response(
            $this->twig->render('experience/spatial/edit.html.twig', [
                'shell' => $shell,
                'page' => $this->pages->create(
                    PageArchetype::FormEditor,
                    ['PageHeader', 'FormSection', 'StickyActions', 'EntityList'],
                    'normal',
                ),
                'scene' => $scene,
                'properties' => $this->scenes->propertyOptions(),
                'actionStatus' => trim((string) $request->query->get('status_message', '')),
            ]),
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
        );
    }

    public function save(Request $request, ?string $id = null): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;

        $input = $request->request->all();
        $input['id'] = max(0, (int) ($id ?? ($input['id'] ?? 0)));
        $result = $this->scenes->save($input, $this->actor($tenant));
        $target = !empty($result['id']) ? '/spatial/edit/' . (int) $result['id'] : '/spatial/edit';

        return $this->redirectStatus($target, (string) ($result['message'] ?? 'Spatial scene updated.'));
    }

    public function upload(Request $request, string $id): Response
    {
        $tenant = $this->manager();
        if ($tenant instanceof Response) return $tenant;

        $sceneId = (int) $id;
        try {
            $asset = $this->scenes->upload(
                $sceneId,
                $this->uploadFile($request->files->get('asset_file')),
                $request->request->all(),
                $this->actor($tenant),
            );

            if ($this->wantsJson($request)) {
                return new JsonResponse(['ok' => true, 'message' => 'Asset завантажено та поставлено в обробку.', 'asset' => $asset], Response::HTTP_CREATED);
            }

            return $this->redirectStatus('/spatial/edit/' . $sceneId, 'Asset завантажено та поставлено в обробку.');
        } catch (Throwable $error) {
            if ($this->wantsJson($request)) {
                return new JsonResponse(['ok' => false, 'message' => $error->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
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
            return new Response('Spatial scene was not found.', Response::HTTP_NOT_FOUND);
        }

        return new Response(
            $this->twig->render('experience/public/spatial_scene.html.twig', [
                'page' => $this->pages->create(
                    PageArchetype::MapSpatial,
                    ['PageHeader', 'Toolbar'],
                    'normal',
                ),
                'scene' => $scene,
                'islandAssets' => $this->vite->assets(['spatial-viewer']),
                'canonicalUrl' => $request->getSchemeAndHttpHost() . '/spatial/scene/' . rawurlencode((string) $scene['slug']),
            ]),
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'public, max-age=60',
            ],
        );
    }

    private function manager(): TenantContext|Response
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) return new RedirectResponse('/auth/login');
        if (!$tenant->isManager()) return new Response('Forbidden', Response::HTTP_FORBIDDEN);

        return $tenant;
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
