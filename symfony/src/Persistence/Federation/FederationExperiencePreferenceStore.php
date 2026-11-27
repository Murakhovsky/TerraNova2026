<?php
declare(strict_types=1);

namespace App\Persistence\Federation;

use App\Web\Experience\Adaptive\ExperienceMode;
use Doctrine\DBAL\Connection;
use App\Web\Experience\Adaptive\ExperienceState;
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
        $state = new ExperienceState($workspaceKey, $entityKey, $mode, $expandedSections);
        $this->db->transactional(function () use ($user, $state): void {
            $this->lockProfile($user);
            $this->writeState($user, $state);
        });
    }

    /** Update only the requested section under the actor's profile lock. */
    public function setWorkspaceSection(
        TenantContext $user, string $workspaceKey, string $entityKey,
        ExperienceMode $mode, string $section, bool $expanded,
    ): void {
        $empty = new ExperienceState($workspaceKey, $entityKey, $mode);
        $this->db->transactional(function () use ($user, $empty, $section, $expanded): void {
            $this->lockProfile($user);
            $current = $this->readState($user, $empty, true);
            $next = $current->withSection($section, $expanded);
            if ($next !== $current) {
                $this->writeState($user, $next);
            }
        });
    }

    /** @return array{mode:ExperienceMode,expanded_sections:list<string>} */
    public function workspace(TenantContext $user, string $workspaceKey, string $entityKey = ''): array
    {
        $empty = new ExperienceState($workspaceKey, $entityKey, $this->defaultMode($user));
        $state = $this->readState($user, $empty);
        return ['mode' => $state->selectedMode, 'expanded_sections' => $state->expandedSections];
    }

    private function readState(TenantContext $user, ExperienceState $empty, bool $lock = false): ExperienceState
    {
        $row = $this->db->fetchAssociative(
            'SELECT selected_mode, state_json, state_version FROM cos_workspace_experience_states
             WHERE organization_id = :org AND user_id = :user AND workspace_key = :workspace AND entity_key = :entity'
                . ($lock ? ' FOR UPDATE' : ''),
            self::identity($user) + ['workspace' => $empty->workspaceKey, 'entity' => $empty->entityKey],
        );
        return $row ? ExperienceState::fromStored($empty->workspaceKey, $empty->entityKey, $row) : $empty;
    }

    private function lockProfile(TenantContext $user): void
    {
        // All workspace writes lock the same authenticated profile first.
        $this->db->executeStatement(
            'INSERT INTO cos_experience_profiles
             (organization_id, user_id, default_mode, profile_version, settings_json, updated_at)
             VALUES (:org, :user, :mode, 1, :settings, :now)
             ON DUPLICATE KEY UPDATE profile_version = profile_version',
            self::identity($user) + [
                'mode' => ExperienceMode::Result->value,
                'settings' => '{"schema_version":"1.0.0"}',
                'now' => self::now(),
            ],
        );
    }

    private function writeState(TenantContext $user, ExperienceState $state): void
    {
        $this->db->executeStatement(
            'INSERT INTO cos_workspace_experience_states
             (organization_id, user_id, workspace_key, entity_key, selected_mode, state_version, state_json, updated_at)
             VALUES (:org, :user, :workspace, :entity, :mode, 1, :state, :now)
             ON DUPLICATE KEY UPDATE selected_mode = VALUES(selected_mode),
                 state_version = state_version + 1,
                 state_json = VALUES(state_json), updated_at = VALUES(updated_at)',
            self::identity($user) + [
                'workspace' => $state->workspaceKey, 'entity' => $state->entityKey,
                'mode' => $state->selectedMode->value, 'state' => json_encode([
                    'schema_version' => ExperienceState::SCHEMA_VERSION,
                    'expanded_sections' => $state->expandedSections,
                ], JSON_THROW_ON_ERROR), 'now' => self::now(),
            ],
        );
    }

    /** @return array{org:string,user:string} */
    private static function identity(TenantContext $user): array
    {
        return ['org' => $user->organizationId()->value(), 'user' => $user->userId()->value()];
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s') . '.000000';
    }
}
