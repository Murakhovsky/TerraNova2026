<?php

declare(strict_types=1);

namespace App\Web\Experience\Native\Contract;

use App\Application\Experience\Device\DeviceCapabilities;
use App\Web\Experience\Native\SurfaceContext;

interface NativeBridgeInterface
{
    public function surface(): SurfaceContext;

    public function capabilities(): DeviceCapabilities;

    public function isAvailable(): bool;
}
