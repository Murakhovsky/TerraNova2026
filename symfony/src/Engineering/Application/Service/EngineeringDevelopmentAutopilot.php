<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

use App\Engineering\Application\Lock\EngineeringWorkflowLockInterface;
use App\Engineering\Application\Persistence\EngineeringFeatureStoreInterface;
use App\Engineering\Application\Persistence\EngineeringWorkflowStoreInterface;
use App\Engineering\Application\Repository\EngineeringRepositoryGatewayInterface;
use App\Engineering\Domain\Workflow\EngineeringId;
use RuntimeException;
use Throwable;

/**
 * One-at-a-time, fail-closed Issue intake into the existing Engineering Runtime.
 * This is NOT a second code executor or a merge/deploy authority.
 */
final readonly class EngineeringDevelopmentAutopilot
{
    public function __construct(
        private EngineeringRepositoryGatewayInterface $repository,
        private EngineeringFeatureStoreInterface $features,
        private EngineeringWorkflowStoreInterface $workflows,
        private EngineeringIssueIntakeService $intake,
        private EngineeringOrchestrator $engineering,
        private EngineeringWorkflowLockInterface $lock,
        private EngineeringAutopilotAdmissionPolicy $policy = new EngineeringAutopilotAdmissionPolicy(),
    ) {}

    /** @return array<string,mixed> */
    public function tick(string $organizationId, bool $execute = false, int $scanLimit = 50): array
    {
        if (trim($organizationId) === '') throw new RuntimeException('Autopilot requires a real organization.');
        if (!$this->repository->available()) throw new RuntimeException('Autopilot GitHub gateway is unavailable.');
        $scanLimit = max(1, min(100, $scanLimit));

        return $this->lock->synchronized('autopilot:admission:'.$organizationId, function () use (
            $organizationId, $execute, $scanLimit,
        ): array {
            // P0: only one active Engineering workflow across this tenant. An
            // awaiting-human workflow also blocks new intake until resolved.
            $active = $this->workflows->activeForOrganization($organizationId, 100);
            if ($active !== []) {
                return [
                    'status' => 'BUSY', 'dry_run' => !$execute,
                    'reason' => 'Existing Engineering workflow requires completion or operator action.',
                    'active_count_at_least' => count($active),
                ];
            }

            $issues = $this->repository->openIssuesByLabel('cos:autopilot:ready', $scanLimit);
            usort($issues, static function (array $a, array $b): int {
                $rank = static function (array $issue): int {
                    $labels = array_map('strtoupper', array_map('strval', $issue['labels'] ?? []));
                    foreach (['P0' => 0, 'P1' => 1, 'P2' => 2, 'P3' => 3] as $key => $value) {
                        if (in_array($key, $labels, true)) return $value;
                    }
                    return 2;
                };
                return ($rank($a) <=> $rank($b))
                    ?: ((int) ($a['number'] ?? 0) <=> (int) ($b['number'] ?? 0));
            });

            $skipped = [];
            foreach ($issues as $issue) {
                $number = (int) ($issue['number'] ?? 0);
                if ($number < 1) continue;
                try {
                    $existing = $this->features->findByExternalIssueId($organizationId, $number);
                    if ($existing !== null) {
                        $record = $this->features->view($existing);
                        if (($record['status'] ?? '') !== 'NEW') {
                            $skipped[$number] = 'Previously admitted feature '.$existing;
                            continue;
                        }
                        if (!$execute) return ['status' => 'READY_TO_RECOVER', 'dry_run' => true,
                            'issue' => $number, 'feature_id' => $existing, 'skipped' => $skipped];
                        $queued = $this->engineering->queue($existing, $organizationId,
                            'autopilot:recovery:'.$number.':'.EngineeringId::generate());
                        return ['status' => 'RECOVERED', 'dry_run' => false, 'issue' => $number,
                            'feature_id' => $existing, 'workflow_id' => $queued->workflowId];
                    }

                    $decision = $this->policy->evaluate($issue, function (int $dep): bool {
                        $dependency = $this->repository->issue($dep);
                        return $dependency['state'] === 'closed' && !$dependency['is_pull_request'];
                    });
                    if (!$decision['admitted']) {
                        $skipped[$number] = $decision['reason'];
                        continue;
                    }
                    if (!$execute) return ['status' => 'READY', 'dry_run' => true, 'issue' => $number,
                        'priority' => $decision['priority'], 'paths' => $decision['paths'],
                        'dependencies' => $decision['dependencies'], 'skipped' => $skipped];

                    // Intake persists first; if a crash occurs before queueing,
                    // a subsequent tick recovers the NEW feature instead of duplicating.
                    $imported = $this->intake->import($number, false, $organizationId,
                        'autopilot:issue:'.$number.':'.EngineeringId::generate(),
                        $decision['paths']);
                    $featureId = (string) $imported['feature_id'];
                    $queued = $this->engineering->queue($featureId, $organizationId,
                        'autopilot:queue:'.$number.':'.EngineeringId::generate());
                    $this->repository->addIssueLabels($number, ['cos:autopilot:claimed']);
                    return ['status' => 'QUEUED', 'dry_run' => false, 'issue' => $number,
                        'feature_id' => $featureId, 'workflow_id' => $queued->workflowId,
                        'priority' => $decision['priority'], 'paths' => $decision['paths']];
                } catch (Throwable $error) {
                    $skipped[$number] = 'Blocked: '.mb_substr($error->getMessage(), 0, 180);
                }
            }
            return ['status' => 'IDLE', 'dry_run' => !$execute,
                'scanned' => count($issues), 'skipped' => $skipped];
        });
    }
}
