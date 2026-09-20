<?php

declare(strict_types=1);

namespace App\Http\Api\Spatial;

use App\Infrastructure\Spatial\SpatialTokenIssuer;
use App\Security\CosSecurityUser;
use Domains\Spatial\Application\Contract\SpatialProcessingInterface;
use Domains\Spatial\Application\Contract\SpatialSceneInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Throwable;

final readonly class SpatialController
{
    private const EDIT_ROLES = ['admin', 'manager', 'realtor', 'partner', 'developer'];

    public function __construct(
        private SpatialSceneInterface $scenes,
        private SpatialProcessingInterface $processor,
        private SpatialTokenIssuer $tokens,
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    public function token(Request $request): JsonResponse
    {
        $result = $this->tokens->issue($this->input($request));
        return new JsonResponse($result, (int) ($result['status'] ?? 200));
    }

    public function scene(string $publicId): JsonResponse
    {
        $scene = $this->scenes->publicScene($publicId);
        return $scene !== null
            ? new JsonResponse(['ok' => true, 'scene' => $scene])
            : new JsonResponse(['ok' => false, 'message' => 'Scene not found.'], 404);
    }

    public function saveScene(Request $request): JsonResponse
    {
        $user = $this->editor();
        if ($user === null) {
            return $this->unauthorized();
        }

        return new JsonResponse($this->scenes->save($this->input($request), $user));
    }

    public function uploadAsset(int $id, Request $request): JsonResponse
    {
        $user = $this->editor();
        if ($user === null) {
            return $this->unauthorized();
        }

        try {
            $file = $request->files->get('file');
            return new JsonResponse([
                'ok' => true,
                'asset' => $this->scenes->upload($id, $this->upload($file), $request->request->all(), $user),
            ], 201);
        } catch (Throwable $error) {
            return new JsonResponse(['ok' => false, 'message' => $error->getMessage()], 422);
        }
    }

    public function externalAsset(int $id, Request $request): JsonResponse
    {
        if ($this->editor() === null) {
            return $this->unauthorized();
        }

        try {
            return new JsonResponse([
                'ok' => true,
                'asset' => $this->scenes->externalAsset($id, $this->input($request)),
            ], 201);
        } catch (Throwable $error) {
            return new JsonResponse(['ok' => false, 'message' => $error->getMessage()], 422);
        }
    }

    public function capture(int $id, Request $request): JsonResponse
    {
        $user = $this->editor();
        if ($user === null) {
            return $this->unauthorized();
        }

        try {
            return new JsonResponse([
                'ok' => true,
                'capture' => $this->scenes->capture($id, $this->input($request), $user),
            ], 201);
        } catch (Throwable $error) {
            return new JsonResponse(['ok' => false, 'message' => $error->getMessage()], 422);
        }
    }

    public function saveHotspot(int $id, Request $request): JsonResponse
    {
        if ($this->editor() === null) {
            return $this->unauthorized();
        }

        try {
            return new JsonResponse([
                'ok' => true,
                'hotspot' => $this->scenes->saveHotspot($id, $this->input($request)),
            ]);
        } catch (Throwable $error) {
            return new JsonResponse(['ok' => false, 'message' => $error->getMessage()], 422);
        }
    }

    public function publish(int $id): JsonResponse
    {
        if ($this->editor() === null) {
            return $this->unauthorized();
        }

        $result = $this->scenes->publish($id);
        return new JsonResponse($result, !empty($result['ok']) ? 200 : 422);
    }

    public function job(string $publicId): JsonResponse
    {
        if ($this->editor() === null) {
            return $this->unauthorized();
        }

        $job = $this->processor->job($publicId);
        return $job !== null
            ? new JsonResponse(['ok' => true, 'job' => $job])
            : new JsonResponse(['ok' => false, 'message' => 'Job not found.'], 404);
    }

    public function event(Request $request): JsonResponse
    {
        $input = $this->input($request);
        $ok = $this->scenes->recordEvent(
            (string) ($input['scene'] ?? ''),
            $input,
            $this->actor(),
        );

        return new JsonResponse(['ok' => $ok], $ok ? 202 : 404);
    }

    private function input(Request $request): array
    {
        if (str_contains(mb_strtolower((string) $request->headers->get('Content-Type', '')), 'application/json')) {
            $decoded = json_decode((string) $request->getContent(), true);
            return is_array($decoded) && !array_is_list($decoded) ? $decoded : [];
        }

        return $request->request->all();
    }

    private function actor(): ?array
    {
        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof CosSecurityUser) {
            return null;
        }

        return [
            'id' => $user->id(),
            'organization_id' => $user->organizationId(),
            'email' => $user->email(),
            'role' => $user->organizationRole(),
            'full_name' => '',
        ];
    }

    private function editor(): ?array
    {
        $actor = $this->actor();
        return $actor !== null && in_array((string) $actor['role'], self::EDIT_ROLES, true)
            ? $actor
            : null;
    }

    private function upload(mixed $file): array
    {
        if (!$file instanceof UploadedFile) {
            return ['error' => UPLOAD_ERR_NO_FILE];
        }

        return [
            'name' => $file->getClientOriginalName(),
            'type' => $file->getClientMimeType(),
            'tmp_name' => $file->getPathname(),
            'error' => $file->getError(),
            'size' => $file->getSize() ?: 0,
        ];
    }

    private function unauthorized(): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'message' => 'Unauthorized.'], 401);
    }
}
