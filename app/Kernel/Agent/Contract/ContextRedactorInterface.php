<?php
declare(strict_types=1);

namespace Kernel\Agent\Contract;

interface ContextRedactorInterface
{
    public function redact(array $context): array;
}
