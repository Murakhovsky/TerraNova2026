<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

use App\Engineering\Application\Persistence\EngineeringArtifactStoreInterface;
use App\Engineering\Application\Persistence\EngineeringFeatureStoreInterface;
use App\Engineering\Domain\Artifact\ArtifactType;
use RuntimeException;

final readonly class EngineeringLearningService
{
    public function __construct(
        private EngineeringFeatureStoreInterface $features,
        private EngineeringArtifactStoreInterface $artifacts,
    ) {}

    /** @param array<string,mixed> $record */
    public function record(string $featureId, array $record, string $createdBy = 'HUMAN'): array
    {
        $this->features->view($featureId);

        foreach (['defect_id','root_cause','failed_role','missed_gate','new_rule','regression_test','eval_case','documentation_update'] as $field) {
            if (!array_key_exists($field, $record)) {
                throw new RuntimeException('Engineering learning record missing '.$field.'.');
            }
            if (in_array($field, ['defect_id','root_cause','failed_role','missed_gate','new_rule'], true)
                && trim((string) $record[$field]) === '') {
                throw new RuntimeException('Engineering learning record field '.$field.' cannot be empty.');
            }
        }

        if (!is_array($record['regression_test']) || $record['regression_test'] === []) {
            throw new RuntimeException('Engineering learning requires a regression test reference.');
        }
        if (!is_array($record['eval_case']) || $record['eval_case'] === []) {
            throw new RuntimeException('Engineering learning requires an eval case reference.');
        }

        return $this->artifacts->createVersion(
            $featureId,
            ArtifactType::ENGINEERING_LEARNING,
            [
                'defect_id' => (string) $record['defect_id'],
                'root_cause' => (string) $record['root_cause'],
                'failed_role' => (string) $record['failed_role'],
                'missed_gate' => (string) $record['missed_gate'],
                'new_rule' => (string) $record['new_rule'],
                'regression_test' => $record['regression_test'],
                'eval_case' => $record['eval_case'],
                'documentation_update' => $record['documentation_update'],
                'recorded_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ],
            createdByAgent: $createdBy,
        );
    }
}
