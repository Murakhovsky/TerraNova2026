<?php
declare(strict_types=1);

namespace Kernel\Module;

use DomainException;
use JsonException;

/**
 * Read-only archive of reviewed, commit-pinned historical contract bytes.
 * This NEVER resolves a handler, grants Policy/Approval or permits replay.
 * Historical execution requires a separate compatible adapter registration.
 */
final readonly class CapabilityVersionArchive
{
    public function __construct(private string $root)
    {
    }

    /** @return array<string,mixed> */
    public function read(string $id, string $version, string $digest): array
    {
        $relative = self::relativePath($id, $version, $digest);
        $base = realpath(rtrim($this->root, '/') . '/resources/contracts/history');
        $full = realpath(rtrim($this->root, '/') . '/' . $relative);
        if ($base === false || $full === false
            || !str_starts_with($full, $base . DIRECTORY_SEPARATOR)
            || !is_file($full)) {
            throw new DomainException('Historical Capability snapshot unavailable.');
        }
        $size = filesize($full);
        if ($size === false || $size > 1048576 || $size === 0) {
            throw new DomainException('Historical Capability snapshot size invalid.');
        }
        try {
            $source = file_get_contents($full);
            if (!is_string($source)) {
                throw new DomainException('Historical Capability snapshot unreadable.');
            }
            $frozen = json_decode($source, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new DomainException('Malformed historical Capability JSON.', previous: $e);
        }
        if (!is_array($frozen) || array_is_list($frozen)) {
            throw new DomainException('Invalid historical Capability archive envelope.');
        }
        $pin = CapabilityContractFingerprint::verifyFrozen($frozen);
        if ($pin['capability_id'] !== $id || $pin['version'] !== $version
            || !hash_equals($pin['contract_digest'], $digest)) {
            throw new DomainException('Historical Capability identity or digest mismatch.');
        }
        return $frozen;
    }

    public static function relativePath(string $id, string $version, string $digest): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/D', $id)
            || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $version)
            || !preg_match('/^[a-f0-9]{64}$/D', $digest)) {
            throw new DomainException('Untrusted historical Capability archive locator.');
        }
        return 'resources/contracts/history/'.$id.'/'.$version.'/'.$digest.'.json';
    }
}
