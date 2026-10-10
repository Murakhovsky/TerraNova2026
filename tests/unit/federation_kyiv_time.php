<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/symfony/src/Web/Federation/FederationKyivTime.php';

use App\Web\Federation\FederationKyivTime;

$cases = [
    '2026-01-10 12:00:00.123456' => '10.01.2026 14:00:00.123456 +02:00',
    '2026-10-09 12:00:00.123456' => '09.10.2026 15:00:00.123456 +03:00',
    '2026-10-25T00:30:00.500000+00:00' => '25.10.2026 03:30:00.500000 +03:00',
    '2026-10-25T01:30:00.500000+00:00' => '25.10.2026 03:30:00.500000 +02:00',
];
foreach ($cases as $utc => $expected) {
    if (FederationKyivTime::displayUtc($utc) !== $expected) {
        throw new RuntimeException('Kyiv DST conversion or microsecond precision regressed: ' . $utc);
    }
}
try {
    FederationKyivTime::displayUtc('');
    throw new RuntimeException('Empty evidence timestamp was accepted.');
} catch (\InvalidArgumentException) {
}
echo "Federation UTC-to-Kyiv conversion: winter, summer, DST fallback, microseconds passed.\n";
