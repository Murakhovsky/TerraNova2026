<?php
declare(strict_types=1);

namespace Kernel\Observability;

interface StructuredLoggerInterface
{
    public function log(string $level, string $message, array $context = []): void;
}
