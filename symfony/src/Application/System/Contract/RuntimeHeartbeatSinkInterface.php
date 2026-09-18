<?php
declare(strict_types=1);

namespace App\Application\System\Contract;

interface RuntimeHeartbeatSinkInterface
{
    public function record(string $source, string $token): void;
}
