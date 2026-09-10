<?php
declare(strict_types=1);

namespace Kernel\Module;

use InvalidArgumentException;

final class VersionConstraint
{
    private const VERSION_PATTERN = '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/';

    public static function assertVersion(string $version, string $label = 'version'): void
    {
        if (!preg_match(self::VERSION_PATTERN, $version)) {
            throw new InvalidArgumentException(sprintf('Invalid %s: %s.', $label, $version));
        }
    }

    public static function assertConstraint(string $constraint): void
    {
        if (!self::isValidConstraint($constraint)) {
            throw new InvalidArgumentException(sprintf('Invalid version constraint: %s.', $constraint));
        }
    }

    public static function matches(string $version, string $constraint): bool
    {
        self::assertVersion($version);
        $constraint = trim($constraint);
        if ($constraint === '' || $constraint === '*') {
            return true;
        }

        self::assertConstraint($constraint);
        $tokens = preg_split('/[\s,]+/', $constraint, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($tokens as $token) {
            if (!self::matchesToken($version, $token)) {
                return false;
            }
        }

        return true;
    }

    private static function isValidConstraint(string $constraint): bool
    {
        $constraint = trim($constraint);
        if ($constraint === '' || $constraint === '*') {
            return true;
        }

        $tokens = preg_split('/[\s,]+/', $constraint, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) {
            return false;
        }

        foreach ($tokens as $token) {
            if (preg_match('/^[\^~](\d+\.\d+(?:\.\d+)?)$/', $token)) {
                continue;
            }
            if (preg_match('/^(?:>=|<=|>|<|=)?(\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?)$/', $token)) {
                continue;
            }
            return false;
        }

        return true;
    }

    private static function matchesToken(string $version, string $token): bool
    {
        if ($token === '*') {
            return true;
        }

        if (preg_match('/^\^(\d+)\.(\d+)(?:\.(\d+))?$/', $token, $match)) {
            $major = (int) $match[1];
            $minor = (int) $match[2];
            $patch = isset($match[3]) ? (int) $match[3] : 0;
            $lower = sprintf('%d.%d.%d', $major, $minor, $patch);

            if ($major > 0) {
                $upper = sprintf('%d.0.0', $major + 1);
            } elseif ($minor > 0) {
                $upper = sprintf('0.%d.0', $minor + 1);
            } else {
                $upper = sprintf('0.0.%d', $patch + 1);
            }

            return version_compare($version, $lower, '>=')
                && version_compare($version, $upper, '<');
        }

        if (preg_match('/^~(\d+)\.(\d+)(?:\.(\d+))?$/', $token, $match)) {
            $major = (int) $match[1];
            $minor = (int) $match[2];
            $hasPatch = isset($match[3]) && $match[3] !== '';
            $patch = $hasPatch ? (int) $match[3] : 0;
            $lower = sprintf('%d.%d.%d', $major, $minor, $patch);
            $upper = $hasPatch
                ? sprintf('%d.%d.0', $major, $minor + 1)
                : sprintf('%d.0.0', $major + 1);

            return version_compare($version, $lower, '>=')
                && version_compare($version, $upper, '<');
        }

        if (!preg_match('/^(>=|<=|>|<|=)?(.+)$/', $token, $match)) {
            return false;
        }

        $operator = $match[1] !== '' ? $match[1] : '=';
        return version_compare($version, $match[2], $operator);
    }

    private function __construct()
    {
    }
}
