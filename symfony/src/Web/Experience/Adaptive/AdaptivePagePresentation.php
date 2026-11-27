<?php
declare(strict_types=1);

namespace App\Web\Experience\Adaptive;

use App\Persistence\Federation\FederationExperiencePreferenceStore;
use App\Security\SessionCsrfValidator;
use App\Web\Experience\Action\UIAction;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * SSR adapter for UX-04. The caller must first resolve the authenticated
 * workspace and any selected entity. This service only writes UI preferences.
 */
final readonly class AdaptivePagePresentation
{
    public function __construct(
        private AdaptiveExperienceResolver $resolver,
        private FederationExperiencePreferenceStore $preferences,
        private SessionCsrfValidator $csrf,
    ) {}

    /**
     * @param list<ExperienceSemantic> $descriptors Server-declared available components.
     * @param list<string> $triggers Trusted read-model signals, never request input.
     * @param list<UIAction> $actions Already authorized UIActionResolver output.
     * @param list<string> $availableCapabilities Already resolved capabilities.
     * @return array<string,mixed>|Response
     */
    public function prepare(
        Request $request, TenantContext $actor, string $workspace,
        array $descriptors, array $triggers = [], array $actions = [],
        string $entity = '', array $availableCapabilities = [], string $operationClass = 'query',
    ): array|Response {
        $catalog = new ExperienceSemanticsCatalog($descriptors);
        $sections = array_map(static fn (ExperienceSemantic $s): array => [
            'id' => $s->id, 'level' => $s->recommendedMode->defaultLevel(), 'semantic' => $s,
        ], $catalog->all());
        $mode = ExperienceMode::Result;
        $expanded = [];
        $available = true;
        try {
            $mode = $this->preferences->defaultMode($actor);
            $expanded = $this->preferences->workspace($actor, $workspace, $entity)['expanded_sections'];
        } catch (\Throwable) {
            // Invalid/unavailable preferences cannot block native business reads.
            $mode = ExperienceMode::Result;
            $expanded = [];
            $available = false;
        }
        $composition = $this->resolver->compose(new ExperienceContext(
            $actor->userId()->value(), $actor->organizationId()->value(), $mode,
            sections: $sections, availableCapabilities: $availableCapabilities,
            expandedSections: $expanded, triggers: $triggers,
            workspaceKey: $workspace, entityKey: $entity, operationClass: $operationClass,
        ), $actions);
        // The URL is the current, already matched internal SSR route. Never
        // accept a client-supplied redirect target, workspace or actor identity.
        $path = $request->getPathInfo();
        $return = $path . ($request->query->count() > 0 ? '?' . http_build_query($request->query->all(), '', '&', PHP_QUERY_RFC3986) : '');
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, chr(92)) || preg_match('/[\x00-\x1f\x7f]/', $path)) {
            return new Response('Invalid experience path.', 400);
        }
        if ($request->isMethod('POST')) {
            if (!$actor->allows(TenantPermissions::ACCESS) || !$actor->allows(TenantPermissions::MANAGE)
                || !$this->csrf->isValid($request)) {
                return new Response('Forbidden', 403);
            }
            if (!$available) return new Response('Experience preferences unavailable.', 503);
            $input = $request->request->all();
            if (array_diff(array_keys($input), ['csrf_token','mode','section','expand']) !== []) {
                return new Response('Invalid experience preference fields.', 422);
            }
            try {
                if (isset($input['mode'])) {
                    $requested = is_string($input['mode']) ? ExperienceMode::tryFrom($input['mode']) : null;
                    if ($requested === null || isset($input['section']) || isset($input['expand'])) {
                        return new Response('Invalid experience mode.', 422);
                    }
                    $this->preferences->saveDefaultMode($actor, $requested);
                } elseif (!isset($input['mode']) && is_string($input['section'] ?? null)
                    && in_array($input['expand'] ?? null, ['0', '1'], true)) {
                    $id = $input['section'];
                    $reason = $composition->disclosureReasons[$id] ?? null;
                    if ($catalog->get($id) === null || !in_array($reason, ['collapsed_by_mode', 'explicit_expansion'], true)) {
                        return new Response('Section is unavailable or cannot be collapsed.', 422);
                    }
                    // Explicit set (not toggle) is replay-safe. Never collapse a triggered section.
                    $expanded = array_values(array_filter($expanded, static fn (string $v): bool => $v !== $id));
                    if ($input['expand'] === '1') $expanded[] = $id;
                    $this->preferences->saveWorkspace($actor, $workspace, $entity, $mode, $expanded);
                } else {
                    return new Response('Invalid experience preference.', 422);
                }
            } catch (\InvalidArgumentException) {
                return new Response('Invalid experience preference.', 422);
            } catch (\Throwable) {
                return new Response('Experience preferences unavailable.', 503);
            }
            return new RedirectResponse($return, 303);
        }
        return [
            'composition' => $composition, 'mode' => $mode->value, 'available' => $available,
            'url' => $return, 'csrf' => $this->csrf->token($request),
            'triggers' => array_values(array_unique($triggers)),
        ];
    }

    /** A concise factory for the existing semantic contract (no registry or new authority). */
    public static function section(string $id, ExperienceMode $mode, array $triggers = []): ExperienceSemantic
    {
        return new ExperienceSemantic($id, 'component', $id, 'supporting', $mode, 100,
            'mode_default', $triggers);
    }
}
