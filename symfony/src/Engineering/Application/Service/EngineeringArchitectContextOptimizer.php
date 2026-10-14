<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

/**
 * Loss-aware Architect prompt compaction: retain authoritative full files and
 * revision metadata while removing duplicate diffs and bounding patch previews.
 * This never modifies repository evidence, stored artifacts or developer context.
 */
final class EngineeringArchitectContextOptimizer
{
    public const MAX_PATCH_BYTES_PER_FILE = 2048;
    public const MAX_PATCH_BYTES_TOTAL = 16384;

    /**
     * @param array<string,mixed>|null $diff
     * @param list<array<string,mixed>> $repositoryFiles
     * @return array<string,mixed>|null
     */
    public function compactRevisionDiff(?array $diff, array $repositoryFiles): ?array
    {
        if ($diff === null) return null;

        $completeFiles = [];
        foreach ($repositoryFiles as $file) {
            if (is_array($file) && ($file['complete'] ?? false) === true && is_string($file['path'] ?? null)) {
                $completeFiles[$file['path']] = true;
            }
        }

        $compacted = $diff;
        $compacted['files'] = [];
        $remaining = self::MAX_PATCH_BYTES_TOTAL;
        $deduplicated = 0;
        $truncated = 0;
        foreach (is_array($diff['files'] ?? null) ? $diff['files'] : [] as $file) {
            if (!is_array($file)) continue;
            $entry = $file;
            $patch = is_string($file['patch'] ?? null) ? $file['patch'] : null;
            unset($entry['patch']);
            if ($patch !== null) {
                if (isset($completeFiles[(string) ($file['path'] ?? '')])) {
                    $entry['patch_source'] = 'complete_repository_file';
                    ++$deduplicated;
                } else {
                    $excerpt = mb_strcut($patch, 0, min(self::MAX_PATCH_BYTES_PER_FILE, $remaining), 'UTF-8');
                    if ($excerpt !== '') $entry['patch'] = $excerpt;
                    $remaining -= strlen($excerpt);
                    if (strlen($excerpt) < strlen($patch)) {
                        $entry['patch_truncated'] = true;
                        ++$truncated;
                    }
                }
            }
            $compacted['files'][] = $entry;
        }
        $compacted['compaction'] = [
            'patches_deduplicated_against_complete_files' => $deduplicated,
            'patches_truncated' => $truncated,
            'patch_preview_limit_bytes' => self::MAX_PATCH_BYTES_TOTAL,
            'patch_preview_bytes_used' => self::MAX_PATCH_BYTES_TOTAL - $remaining,
            'full_patch_available_via_repository_evidence' => true,
        ];

        return $compacted;
    }

    /** @param array<string,mixed> $inputs @return array<string,int> */
    public function serializedBytesByComponent(array $inputs): array
    {
        $sizes = [];
        foreach ($inputs as $name => $value) {
            $json = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $sizes[(string) $name] = strlen(is_string($json) ? $json : '');
        }
        return $sizes;
    }
}
