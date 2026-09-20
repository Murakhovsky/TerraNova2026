<?php
declare(strict_types=1);

namespace App\Web\Cabinet;

use App\Application\Identity\Service\CabinetPortalService;
use App\Web\Phtml\PhtmlRenderer;
use Domains\Property\Application\Contract\PropertySubmissionInterface;
use Infrastructure\Media\MediaStorageService;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class CabinetPageController
{
    public function __construct(
        private PhtmlRenderer $renderer,
        private TenantContextProviderInterface $tenants,
        private CabinetPortalService $portal,
        private PropertySubmissionInterface $submissions,
        private MediaStorageService $media,
    ) {
    }

    public function index(Request $request): Response
    {
        $context = $this->tenants->current();
        if (!$context instanceof TenantContext) {
            return new RedirectResponse('/auth/login');
        }

        $user = $this->portal->user($context);
        if ($user === null) {
            return new RedirectResponse('/auth/login');
        }

        $status = null;
        $data = ['my_properties' => [], 'submissions' => [], 'inbound_requests' => []];

        try {
            $data = $this->portal->dashboard($context, $user);
        } catch (Throwable) {
            $status = 'Дані кабінету тимчасово недоступні. Спробуйте оновити сторінку трохи пізніше.';
        }

        return $this->page($request, 'cabinet/index', $context, $user, [
            'myProperties' => $data['my_properties'],
            'submissions' => $data['submissions'],
            'inboundRequests' => $data['inbound_requests'],
            'pageStatus' => $status,
        ], $status === null ? 200 : 503);
    }

    public function submission(Request $request, int $id): Response
    {
        $context = $this->tenants->current();
        if (!$context instanceof TenantContext) {
            return new RedirectResponse('/auth/login');
        }

        $user = $this->portal->user($context);
        if ($user === null) {
            return new RedirectResponse('/auth/login');
        }

        $pageStatus = null;
        $actionStatus = null;
        $submission = null;
        $media = [];

        try {
            if ($request->isMethod('POST')) {
                $result = $this->submissions->updateForUser(
                    $id,
                    $user,
                    $request->request->all(),
                    $this->legacyFiles($request),
                );
                $actionStatus = (string) ($result['message'] ?? '');
            }

            $submission = $this->submissions->submissionForUser($id, $user);
            if ($submission === null) {
                return $this->page($request, 'cabinet/submission', $context, $user, [
                    'submission' => null,
                    'media' => [],
                    'formData' => $request->request->all(),
                    'pageStatus' => 'Заявку не знайдено або вона належить іншому користувачу.',
                    'actionStatus' => $actionStatus,
                ], 404);
            }

            $media = $this->media->assetsFor('property_submission', $id);
        } catch (Throwable) {
            $pageStatus = 'Редагування заявки тимчасово недоступне.';
        }

        return $this->page($request, 'cabinet/submission', $context, $user, [
            'submission' => $submission,
            'media' => $media,
            'formData' => array_replace(is_array($submission) ? $submission : [], $request->request->all()),
            'pageStatus' => $pageStatus,
            'actionStatus' => $actionStatus,
        ], $pageStatus === null ? 200 : 503);
    }

    /** @param array<string,mixed> $variables */
    private function page(
        Request $request,
        string $view,
        TenantContext $context,
        array $user,
        array $variables,
        int $status,
    ): Response {
        $role = $context->role()->value();
        $capabilities = $this->portal->roleCapabilities();

        return new Response($this->renderer->render($request, $view, array_replace([
            'title' => 'Кабінет',
            'metaTitle' => 'Кабінет | Terra Nova CLUB',
            'metaRobots' => 'noindex,nofollow',
            'interfaceSurface' => 'portal',
            'pageAssetEntries' => ['portal-cabinet'],
            'user' => $user,
            'currentUser' => $user,
            'portalRole' => $role,
            'portalCapabilities' => $capabilities[$role] ?? [],
            'canSubmitProperty' => !empty(($capabilities[$role] ?? [])['submit_property']),
            'portalNavigation' => [
                'primary' => [
                    ['key' => 'cabinet', 'path' => 'cabinet', 'label' => 'Кабінет'],
                    ['key' => 'catalog', 'path' => 'property/catalog', 'label' => 'Каталог'],
                ],
                'utility' => [
                    ['key' => 'logout', 'path' => 'auth/logout', 'label' => 'Вийти'],
                ],
            ],
        ], $variables)), $status);
    }

    /** @return array<string,mixed> */
    private function legacyFiles(Request $request): array
    {
        $result = [];
        foreach ($request->files->all() as $key => $value) {
            $result[$key] = $this->legacyFileValue($value);
        }

        return $result;
    }

    private function legacyFileValue(mixed $value): mixed
    {
        if ($value instanceof UploadedFile) {
            return [
                'name' => $value->getClientOriginalName(),
                'type' => $value->getClientMimeType(),
                'tmp_name' => $value->getPathname(),
                'error' => $value->getError(),
                'size' => $value->getSize() ?: 0,
            ];
        }

        if (is_array($value)) {
            $converted = [];
            foreach ($value as $key => $item) {
                $converted[$key] = $this->legacyFileValue($item);
            }
            return $converted;
        }

        return $value;
    }
}
