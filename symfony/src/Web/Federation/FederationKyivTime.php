<?php
declare(strict_types=1);

namespace App\Web\Federation;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Federation storage/evidence is UTC; operator dates use actual Kyiv civil time.
 * Never hardcode +02:00: Kyiv observes +03:00 during summer in 2026.
 */
final class FederationKyivTime
{
    private const ZONE = 'Europe/Kyiv';

    public static function displayUtc(string $utc): string
    {
        if ($utc === '') {
            throw new InvalidArgumentException('Empty Federation timestamp.');
        }
        // SQL DATETIME(6) has no offset and is written in UTC by Federation.
        // Evidence ISO-8601 timestamps contain an explicit source offset.
        $instant = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        return $instant->setTimezone(new DateTimeZone(self::ZONE))->format('d.m.Y H:i:s.u P');
    }
}
