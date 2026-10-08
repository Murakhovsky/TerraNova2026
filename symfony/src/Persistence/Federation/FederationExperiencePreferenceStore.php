<?php
declare(strict_types=1);

namespace App\Persistence\Federation;

use App\Web\Experience\Adaptive\ExperienceMode;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Kernel\Tenant\Model\TenantContext;

/**
 * Preferences follow the authenticated user + tenant. Modes never influence authorization.
 */
final readonly class FederationExperiencePreferenceStore
{
    public function __construct(private Connection $db)
    {
    }

    public function defaultMode(TenantContext $user): ExperienceMode
    {
        $raw = $this->db->fetchOne(
            'SELECT default_mode FROM cos_experience_profiles WHERE organization_id = :org AND user_id = :user',
            self::identity($user),
        );
        return is_string($raw) ? (ExperienceMode::tryFrom($raw) ?? ExperienceMode::Result) : ExperienceMode::Result;
    }

    public function saveDefaultMode(TenantContext $user, ExperienceMode $mode): void
    {
        $this->db->executeStatement(
            'INSERT INTO cos_experience_profiles
             (organization_id, user_id, default_mode, profile_version, settings_json, updated_at)
             VALUES (:org, :user, :mode, 1, :settings, :now)
             ON DUPLICATE KEY UPDATE default_mode = VALUES(default_mode),
                 profile_version = profile_version + 1, updated_at = VALUES(updated_at)',
            self::identity($user) + [
                'mode' => $mode->value,
                'settings' => '{"schema_version":"1.0.0"}',
                'now' => self::now(),
            ],
        );
    }

    /**
     * @param list<string> $expandedSections
     */
    public function saveWorkspace(
        TenantContext $user,
        string $workspaceKey,
        string $entityKey,
        ExperienceMode $mode,
        array $expandedSections,
    ): void {
        self::key($workspaceKey);
        if (strlen($entityKey) > 190 || str_contains($entityKey, "\0")) {
            throw new InvalidArgumentException('Invalid Experience entity scope.');
        }
        if (!array_is_list($expandedSections) || count($expandedSections) > 64) {
            throw new InvalidArgumentException('Expanded Experience sections must be a bounded list.');
        }
        foreach ($expandedSections as $section) {
            self::key($section);
        }
        if (count($expandedSections) !== count(array_unique($expandedSections))) {
            throw new InvalidArgumentException('Duplicated Experience section preferences.');
        }
        $state = json_encode([
            'schema_version' => '1.0.0', 'expanded_sections' => $expandedSections,
        ], JSON_THROW_ON_ERROR);

        $this->db->transactional(function () use ($user, $workspaceKey, $entityKey, $mode, $state): void {
            $this->db->executeStatement(
                'INSERT IGNORE INTO cos_experience_profiles
                 (organization_id, user_id, default_mode, profile_version, settings_json, updated_at)
                 VALUES (:org, :user, :mode, 1, :settings, :now)',
                self::identity($user) + [
                    'mode' => ExperienceMode::Result->value,
                    'settings' => '{"schema_version":"1.0.0"}',
                    'now' => self::now(),
                ],
            );
            $this->db->executeStatement(
                'INSERT INTO cos_workspace_experience_states
                 (organization_id, user_id, workspace_key, entity_key, selected_mode, state_version, state_json, updated_at)
                 VALUES (:org, :user, :workspace, :entity, :mode, 1, :state, :now)
                 ON DUPLICATE KEY UPDATE selected_mode = VALUES(selected_mode),
                     state_version = state_version + 1,
                     state_json = VALUES(state_json), updated_at = VALUES(updated_at)',
                self::identity($user) + [
                    'workspace' => $workspaceKey, 'entity' => $entityKey,
                    'mode' => $mode->value, 'state' => $state, 'now' => self::now(),
                ],
            );
        });
    }

    /** @return array{mode:ExperienceMode,expanded_sections:list<string>} */
    public function workspace(TenantContext $user, string $workspaceKey, string $entityKey = ''): array
    {
        self::key($workspaceKey);
        if (strlen($entityKey) > 190 || str_contains($entityKey, "\0")) {
            throw new InvalidArgumentException('Invalid Experience entity scope.');
        }
        $row = $this->db->fetchAssociative(
            'SELECT selected_mode, state_json FROM cos_workspace_experience_states
             WHERE organization_id = :org AND user_id = :user AND workspace_key = :workspace AND entity_key = :entity',
            self::identity($user) + ['workspace' => $workspaceKey, 'entity' => $entityKey],
        );
        if (!$row) {
            return ['mode' => $this->defaultMode($user), 'expanded_sections' => []];
        }
        $state = json_decode((string) $row['state_json'], true, 512, JSON_THROW_ON_ERROR);
        $expanded = is_array($state['expanded_sections'] ?? null) ? $state['expanded_sections'] : [];
        return [
            'mode' => ExperienceMode::tryFrom((string) $row['selected_mode']) ?? ExperienceMode::Result,
            'expanded_sections' => array_values(array_filter($expanded, 'is_string')),
        ];
    }

    /** @return array{org:string,user:string} */
    private static function identity(TenantContext $user): array
    {
        return ['org' => $user->organizationId()->value(), 'user' => $user->userId()->value()];
    }

    private static function key(string $key): void
    {
        if (!preg_match('/^[a-z][a-z0-9_.-]{0,189}$/', $key)) {
            throw new InvalidArgumentException('Invalid Experience workspace or section key.');
        }
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s') . '.000000';
    }
}
