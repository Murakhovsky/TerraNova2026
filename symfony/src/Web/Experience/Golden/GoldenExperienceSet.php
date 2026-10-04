<?php

declare(strict_types=1);

namespace App\Web\Experience\Golden;

use App\Web\Experience\Registry\CompiledPageContractRegistry;
use App\Web\Experience\Registry\PageExperienceStatus;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

final readonly class GoldenExperienceSet
{
    public function __construct(
        private CompiledPageContractRegistry $registry,
        private GoldenDecisionRegistry $decisions,
        #[Autowire('%kernel.project_dir%/../resources/experience/golden-set.yaml')]
        private string $manifest,
    ) {
    }

    public function report(): GoldenExperienceReport
    {
        if (!is_file($this->manifest)) {
            throw new RuntimeException('Golden Experience manifest is missing.');
        }

        $document = Yaml::parseFile($this->manifest);
        if (!is_array($document)) {
            throw new RuntimeException('Golden Experience manifest is invalid.');
        }

        $ids = array_values(array_map('strval', is_array($document['pages'] ?? null) ? $document['pages'] : []));
        $required = (int) ($document['required_count'] ?? count($ids));
        $definition = is_array($document['definition'] ?? null) ? $document['definition'] : [];
        $targetQuality = (int) ($definition['target_quality'] ?? 4);
        $humanRequired = (bool) ($definition['human_approval_required'] ?? true);
        $blockMigration = (bool) ($definition['mass_migration_blocked_until_ready'] ?? true);

        if ($required !== 8 || count($ids) !== 8 || count(array_unique($ids)) !== 8) {
            throw new RuntimeException('Golden Experience Set must contain exactly eight unique page ids.');
        }

        $missing = [];
        $pages = [];
        $implemented = 0;
        $ready = 0;

        foreach ($ids as $id) {
            try {
                $contract = $this->registry->get($id);
            } catch (\InvalidArgumentException) {
                $missing[] = $id;
                continue;
            }

            $isImplemented = !in_array($contract->status, [
                PageExperienceStatus::Discovered,
                PageExperienceStatus::Inventoried,
                PageExperienceStatus::Contracted,
                PageExperienceStatus::UxApproved,
                PageExperienceStatus::Implementing,
                PageExperienceStatus::Blocked,
                PageExperienceStatus::NeedsRework,
                PageExperienceStatus::Deprecated,
                PageExperienceStatus::Superseded,
                PageExperienceStatus::Exempt,
            ], true);

            if ($isImplemented) {
                ++$implemented;
            }

            $scores = $contract->quality->toArray();
            $qualityReady = min($scores) >= $targetQuality;
            $decision = $this->decisions->get($id);
            $humanAccepted = ($decision['decision'] ?? null) === 'ACCEPT';
            $isReady = $contract->status === PageExperienceStatus::V1Ready
                && $qualityReady
                && (!$humanRequired || $humanAccepted);

            if ($isReady) {
                ++$ready;
            }

            $pages[] = [
                'id' => $id,
                'path' => $contract->path,
                'domain' => $contract->domain,
                'archetype' => $contract->archetype,
                'status' => $contract->status->value,
                'quality' => $scores,
                'humanAccepted' => $humanAccepted,
                'humanDecision' => $decision,
                'ready' => $isReady,
            ];
        }

        return new GoldenExperienceReport(
            required: $required,
            registered: count($pages),
            implemented: $implemented,
            ready: $ready,
            pages: $pages,
            missing: $missing,
            massMigrationBlocked: $blockMigration && $ready < $required,
        );
    }
}
