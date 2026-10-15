<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

/** Bounded read-only evidence selection by Manager policy. */
final readonly class EngineeringArchitectEvidencePlanner
{
    public function __construct(
        private EngineeringArchitectEvidenceAuthorization $policy = new EngineeringArchitectEvidenceAuthorization(),
    ) {}

    /** @param list<string> $knownPaths @return array<string,mixed> */
    public function budget(int $round, int $filesUsed, array $knownPaths, ?int $maxRounds = null): array
    {
        $roundLimit = min(EngineeringArchitectEvidenceAuthorization::MAX_ROUNDS, max(1, $maxRounds ?? EngineeringArchitectEvidenceAuthorization::MAX_ROUNDS));
        return [
            'rounds_used' => $round,
            'rounds_max' => $roundLimit,
            'rounds_remaining' => max(0, $roundLimit - $round),
            'files_used' => $filesUsed,
            'files_max' => EngineeringArchitectEvidenceAuthorization::MAX_ADDITIONAL_FILES,
            'files_remaining' => max(0, EngineeringArchitectEvidenceAuthorization::MAX_ADDITIONAL_FILES - $filesUsed),
            'already_available' => array_values(array_unique($knownPaths)),
        ];
    }

    /**
     * @param mixed $requested
     * @param list<string> $alreadyRead
     * @param null|callable(list<string>):list<string> $resolveExisting
     * @return array{status:string,reason:string,paths:list<string>,rejected:list<array<string,string>>,partial:bool,budget:array<string,mixed>}
     */
    public function plan(mixed $requested, array $alreadyRead, int $filesUsed, int $round, ?callable $resolveExisting = null, ?int $maxRounds = null): array
    {
        $budget = $this->budget($round, $filesUsed, $alreadyRead, $maxRounds);
        if (!is_array($requested) || $requested === []
            || count($requested) > EngineeringArchitectEvidenceAuthorization::MAX_FILES_PER_ROUND) {
            return [
                'status' => 'REJECTED', 'reason' => 'INVALID_REQUEST_COUNT',
                'paths' => [], 'rejected' => [], 'partial' => false, 'budget' => $budget,
            ];
        }
        if ($budget['rounds_remaining'] === 0 || $budget['files_remaining'] === 0) {
            return [
                'status' => 'EXHAUSTED',
                'reason' => $budget['rounds_remaining'] === 0 ? 'ROUND_LIMIT' : 'FILE_LIMIT',
                'paths' => [], 'rejected' => [], 'partial' => false, 'budget' => $budget,
            ];
        }

        $accepted = [];
        $rejected = [];
        foreach ($requested as $candidate) {
            try {
                $path = $this->policy->authorize([$candidate], array_merge($alreadyRead, $accepted))[0];
            } catch (\RuntimeException) {
                $value = is_string($candidate) ? $candidate : json_encode($candidate, JSON_PARTIAL_OUTPUT_ON_ERROR);
                $rejected[] = ['path_sha256' => hash('sha256', (string) $value), 'reason' => 'UNAUTHORIZED_OR_DUPLICATE_PATH'];
                continue;
            }
            if (count($accepted) >= $budget['files_remaining']) {
                $rejected[] = ['path_sha256' => hash('sha256', $path), 'reason' => 'FILE_LIMIT'];
                continue;
            }
            $accepted[] = $path;
        }
        if ($accepted !== [] && $resolveExisting !== null) {
            $existing = $resolveExisting($accepted);
            if (!is_array($existing)) {
                throw new \RuntimeException('Repository returned invalid path availability evidence.');
            }
            $knownExisting = array_fill_keys($existing, true);
            $valid = [];
            foreach ($accepted as $path) {
                if (!isset($knownExisting[$path])) {
                    $rejected[] = ['path_sha256' => hash('sha256', $path), 'reason' => 'NOT_FOUND_AT_PINNED_REVISION'];
                } else {
                    $valid[] = $path;
                }
            }
            $accepted = $valid;
        }
        if ($accepted === []) {
            return ['status' => 'REJECTED', 'reason' => 'NO_USABLE_PATHS',
                'paths' => [], 'rejected' => $rejected, 'partial' => false, 'budget' => $budget];
        }
        return ['status' => 'COLLECT', 'reason' => 'COLLECT', 'paths' => $accepted,
            'rejected' => $rejected, 'partial' => $rejected !== [], 'budget' => $budget];
    }
}
