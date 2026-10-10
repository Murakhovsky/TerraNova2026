<?php
declare(strict_types=1);

namespace App\Http\Api\V1\Controller;

use App\Persistence\Federation\FederationExperiencePreferenceStore;
use App\Security\SessionCsrfValidator;
use App\Web\Experience\Adaptive\ExperienceMode;
use InvalidArgumentException;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantPermissions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * Account-scoped experience preferences. No authorization decisions are derived from mode.
 */
final readonly class FederationExperiencePreferenceController
{
    public function __construct(
        private FederationExperiencePreferenceStore $preferences,
        private TenantContextProviderInterface $tenants,
        private SessionCsrfValidator $csrf,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $tenant = $this->tenants->current();
        if ($tenant === null || !$tenant->allows(TenantPermissions::ACCESS)) {
            return new JsonResponse(['ok' => false, 'error' => 'unauthenticated'], 403);
        }
        try {
            $workspace = (string) $request->query->get('workspace', '');
            $entity = (string) $request->query->get('entity', '');
            $result = [
                'default_mode' => $this->preferences->defaultMode($tenant)->value,
            ];
            if ($workspace !== '') {
                $state = $this->preferences->workspace($tenant, $workspace, $entity);
                $result['workspace'] = [
                    'mode' => $state['mode']->value,
                    'expanded_sections' => $state['expanded_sections'],
                ];
            }
            return new JsonResponse(['ok' => true, 'data' => $result]);
        } catch (InvalidArgumentException) {
            return new JsonResponse(['ok' => false, 'error' => 'invalid_experience_scope'], 422);
        } catch (Throwable) {
            return new JsonResponse(['ok' => false, 'error' => 'experience_unavailable'], 500);
        }
    }

    public function update(Request $request): JsonResponse
    {
        $tenant = $this->tenants->current();
        if ($tenant === null || !$tenant->allows(TenantPermissions::ACCESS)) {
            return new JsonResponse(['ok' => false, 'error' => 'unauthenticated'], 403);
        }
        if (!$this->csrf->isValid($request)) {
            return new JsonResponse(['ok' => false, 'error' => 'invalid_csrf_token'], 400);
        }
        $data = json_decode((string) $request->getContent(), true);
        if (!is_array($data) || array_is_list($data)) {
            return new JsonResponse(['ok' => false, 'error' => 'invalid_json'], 422);
        }
        $mode = ExperienceMode::tryFrom(is_string($data['mode'] ?? null) ? $data['mode'] : '');
        if ($mode === null) {
            return new JsonResponse(['ok' => false, 'error' => 'invalid_experience_mode'], 422);
        }
        try {
            if (array_key_exists('workspace', $data)) {
                if (!is_string($data['workspace']) || !is_string($data['entity'] ?? '')
                    || !is_array($data['expanded_sections'] ?? [])) {
                    throw new InvalidArgumentException('Invalid experience workspace payload.');
                }
                $this->preferences->saveWorkspace(
                    $tenant, $data['workspace'], $data['entity'] ?? '', $mode,
                    $data['expanded_sections'] ?? [],
                );
            } else {
                $this->preferences->saveDefaultMode($tenant, $mode);
            }
            return new JsonResponse(['ok' => true, 'mode' => $mode->value]);
        } catch (InvalidArgumentException) {
            return new JsonResponse(['ok' => false, 'error' => 'invalid_experience_preferences'], 422);
        } catch (Throwable) {
            return new JsonResponse(['ok' => false, 'error' => 'experience_persistence_failed'], 500);
        }
    }
}
