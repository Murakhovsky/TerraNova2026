<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

use RuntimeException;

/**
 * Manager-owned deterministic policy for Architect's read-only repository evidence.
 * This is NOT an architecture approval and grants no write or credential access.
 */
final class EngineeringArchitectEvidenceAuthorization
{
    public const MAX_ROUNDS = 2;
    public const MAX_FILES_PER_ROUND = 6;
    public const MAX_ADDITIONAL_FILES = 12;

    /** @param array<string,mixed> $decision */
    public function isLegacyReadOnlyRefresh(array $decision): bool
    {
        if (($decision['type'] ?? null) !== 'WORKFLOW_EVIDENCE_REFRESH'
            || ($decision['recommended_option'] ?? null) !== 'REFRESH_EVIDENCE') return false;
        $options = $decision['options'] ?? null;
        if (!is_array($options) || count($options) !== 2) return false;
        $ids = [];
        foreach ($options as $option) {
            if (!is_array($option) || !is_string($option['id'] ?? null)) return false;
            $ids[] = strtoupper(trim($option['id']));
        }
        sort($ids);
        if ($ids !== ['CANCEL','REFRESH_EVIDENCE']) return false;
        $question = strtolower((string) ($decision['question'] ?? ''));
        $reason = strtolower((string) ($decision['reason'] ?? ''));
        return str_contains($question.' '.$reason, 'read-only')
            && str_contains($reason, 'repository');
    }

    /**
     * @param mixed $requested
     * @param list<string> $alreadyRead
     * @return list<string>
     */
    public function authorize(mixed $requested, array $alreadyRead): array
    {
        if (!is_array($requested) || $requested === [] || count($requested) > self::MAX_FILES_PER_ROUND) {
            throw new RuntimeException('Architect repository evidence request must contain 1 to 6 exact file paths.');
        }

        $known = array_fill_keys($alreadyRead, true);
        $paths = [];
        foreach ($requested as $rawPath) {
            if (!is_string($rawPath)) {
                throw new RuntimeException('Architect repository evidence request must contain only string paths.');
            }
            $path = trim($rawPath);
            if (
                $path === '' || strlen($path) > 240
                || str_contains($path, '\\') || str_contains($path, '//')
                || str_starts_with($path, '/') || str_contains($path, "\0")
                || !preg_match('~^[A-Za-z0-9_./-]+$~D', $path)
            ) {
                throw new RuntimeException('Architect repository evidence path is not an exact safe repository file path.');
            }

            $segments = explode('/', $path);
            foreach ($segments as $segment) {
                if ($segment === '.' || $segment === '..' || (str_starts_with($segment, '.') && !($segment === '.github' && $segment === $segments[0]))) {
                    throw new RuntimeException('Architect repository evidence path includes a hidden or traversal segment.');
                }
                if (in_array(strtolower($segment), [
                    'vendor', 'node_modules', 'var', 'secrets', 'credentials',
                    'private', 'storage', 'uploads', 'user-data', 'user_data',
                ], true)) {
                    throw new RuntimeException('Architect repository evidence request targets a protected location.');
                }
            }

            $name = strtolower((string) end($segments));
            if (
                str_contains($name, 'secret') || str_contains($name, 'credential')
                || in_array($name, ['id_rsa', 'id_ed25519', 'authorized_keys', 'known_hosts'], true)
                || preg_match('/\.(?:pem|key|p12|pfx|keystore|jks|sqlite|db|zip|gz|tar)$/D', $name)
            ) {
                throw new RuntimeException('Architect repository evidence request may include secrets or binary material.');
            }
            // Repository evidence is text-only; binary and unknown formats need explicit handling.
            if (!preg_match('/\\.(?:php|md|txt|json|jsonl|yaml|yml|xml|twig|js|jsx|ts|tsx|css|scss|html|htm|sql|sh|bash|py|go|rs|java|kt|ini|lock|dist)$/D', $name)
                && !in_array($name, ['dockerfile', 'makefile', 'jenkinsfile'], true)
            ) {
                throw new RuntimeException('Architect read-only evidence must be a supported text source file.');
            }
            if (isset($known[$path]) || isset($paths[$path])) {
                throw new RuntimeException('Architect repository evidence request repeats a previously supplied file.');
            }
            $paths[$path] = true;
        }

        return array_keys($paths);
    }
}
