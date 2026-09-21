<?php

declare(strict_types=1);

namespace App\Web\Experience\Extension\Contract;

interface WebExtensionProviderInterface
{
    public function serviceId(): string;
}
