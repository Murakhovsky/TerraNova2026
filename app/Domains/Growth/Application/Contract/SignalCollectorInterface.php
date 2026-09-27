<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

use Domains\Growth\Application\DTO\SignalCollectionBatch;
use Domains\Growth\Application\DTO\SignalCollectionRequest;

interface SignalCollectorInterface
{
    public function name(): string;

    public function collect(SignalCollectionRequest $request): SignalCollectionBatch;
}
