<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use InvalidArgumentException;

/**
 * Checks the original bytes of a claimed independent statement before the
 * corresponding observation can be retained as pending NAV evidence.
 * Hash matching proves byte correspondence, not source authenticity or approval.
 */
final class PortfolioNavSourceDocumentVerifier
{
    public static function verify(string $path, string $claimedDigest): array
    {
        $digest = strtolower(trim($claimedDigest));
        if (preg_match('/^[a-f0-9]{64}$/', $digest) !== 1
            || $path === ''
            || is_link($path)
            || !is_file($path)
            || !is_readable($path)) {
            throw new InvalidArgumentException('Independent financial source document or digest invalid.');
        }
        $size = filesize($path);
        if (!is_int($size) || $size <= 0 || $size > 20971520) {
            throw new InvalidArgumentException('Independent financial source document exceeds size policy.');
        }
        $actual = hash_file('sha256', $path);
        if (!is_string($actual) || !hash_equals($digest, $actual)) {
            throw new InvalidArgumentException('Independent financial source document checksum mismatch.');
        }
        return [
            'sha256' => $actual,
            'size_bytes' => $size,
            'verified_bytes' => true,
            'source_authenticated' => false,
            'reconciled' => false,
        ];
    }
}
