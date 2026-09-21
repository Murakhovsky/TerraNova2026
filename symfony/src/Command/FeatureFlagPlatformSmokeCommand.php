<?php

declare(strict_types=1);

namespace App\Command;

use DateTimeImmutable;
use Platform\FeatureFlag\Contract\FeatureFlagRepositoryInterface;
use Platform\FeatureFlag\Model\FeatureFlagContext;
use Platform\FeatureFlag\Model\FeatureFlagDecisionReason;
use Platform\FeatureFlag\Model\FeatureFlagDefinition;
use Platform\FeatureFlag\Model\FeatureFlagKey;
use Platform\FeatureFlag\Model\FeatureFlagOverride;
use Platform\FeatureFlag\Model\FeatureFlagOverrideScope;
use Platform\FeatureFlag\Service\FeatureFlagResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'cos:platform:feature-flags:smoke',
    description: 'Validate canonical progressive feature flag contracts.',
)]
final class FeatureFlagPlatformSmokeCommand extends Command
{
    public function __construct(
        private readonly FeatureFlagResolver $runtime,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $unknown = $this->runtime->decide(
            new FeatureFlagKey('wave12.20.unknown'),
            new FeatureFlagContext('default', '1', 'web_desktop'),
        );

        if ($unknown->enabled || $unknown->reason !== FeatureFlagDecisionReason::UnknownFlag) {
            $output->writeln('<error>Unknown feature flags must fail closed.</error>');

            return Command::FAILURE;
        }

        $now = new DateTimeImmutable('2026-09-21T12:00:00+00:00');
        $key = new FeatureFlagKey('wave12.20.progressive');
        $definition = new FeatureFlagDefinition(
            key: $key,
            enabled: true,
            rolloutPercentage: 37,
            salt: 'wave12-20-salt-v1',
            description: 'Smoke rollout',
        );

        $repository = new class($definition) implements FeatureFlagRepositoryInterface {
            public ?FeatureFlagOverride $override = null;

            public function __construct(private FeatureFlagDefinition $definition)
            {
            }

            public function definition(FeatureFlagKey $key): ?FeatureFlagDefinition
            {
                return $key->value === $this->definition->key->value ? $this->definition : null;
            }

            public function override(
                FeatureFlagKey $key,
                string $organizationId,
                ?string $userId,
                DateTimeImmutable $at,
            ): ?FeatureFlagOverride {
                return $this->override;
            }

            public function replaceDefinition(FeatureFlagDefinition $definition): void
            {
                $this->definition = $definition;
            }
        };

        $resolver = new FeatureFlagResolver($repository);
        $desktop = new FeatureFlagContext('default', '42', 'web_desktop');
        $pwa = new FeatureFlagContext('default', '42', 'pwa');

        $desktopDecision = $resolver->decide($key, $desktop, $now);
        $pwaDecision = $resolver->decide($key, $pwa, $now);

        if (
            $desktopDecision->bucket === null
            || $desktopDecision->bucket !== $pwaDecision->bucket
            || $desktopDecision->enabled !== $pwaDecision->enabled
        ) {
            $output->writeln('<error>Progressive rollout assignment must be deterministic across surfaces.</error>');

            return Command::FAILURE;
        }

        $repository->override = new FeatureFlagOverride(
            scope: FeatureFlagOverrideScope::User,
            subjectId: '42',
            enabled: true,
            reason: 'smoke canary',
        );

        $overrideDecision = $resolver->decide($key, $desktop, $now);
        if (!$overrideDecision->enabled || $overrideDecision->reason !== FeatureFlagDecisionReason::UserOverride) {
            $output->writeln('<error>User override must beat percentage rollout.</error>');

            return Command::FAILURE;
        }

        $repository->replaceDefinition(new FeatureFlagDefinition(
            key: $key,
            enabled: false,
            rolloutPercentage: 100,
            salt: 'wave12-20-salt-v1',
        ));

        $killSwitch = $resolver->decide($key, $desktop, $now);
        if ($killSwitch->enabled || $killSwitch->reason !== FeatureFlagDecisionReason::MasterDisabled) {
            $output->writeln('<error>Master disabled state must beat explicit overrides.</error>');

            return Command::FAILURE;
        }

        $output->writeln('COS Feature Flag Platform runtime passed.');

        return Command::SUCCESS;
    }
}
