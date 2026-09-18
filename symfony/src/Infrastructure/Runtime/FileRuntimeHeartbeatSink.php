<?php
declare(strict_types=1);

namespace App\Infrastructure\Runtime;

use App\Application\System\Contract\RuntimeHeartbeatSinkInterface;
use DateTimeImmutable;
use RuntimeException;

final readonly class FileRuntimeHeartbeatSink implements RuntimeHeartbeatSinkInterface
{
    public function __construct(private string $runtimeDirectory)
    {
    }

    public function record(string $source, string $token): void
    {
        if (!is_dir($this->runtimeDirectory) && !mkdir($this->runtimeDirectory, 0775, true) && !is_dir($this->runtimeDirectory)) {
            throw new RuntimeException('Cannot create runtime heartbeat directory.');
        }

        $payload = json_encode([
            'source' => $source,
            'token' => $token,
            'recorded_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        ], JSON_THROW_ON_ERROR);

        file_put_contents($this->runtimeDirectory . '/scheduler-heartbeat.json', $payload, LOCK_EX);
    }
}
