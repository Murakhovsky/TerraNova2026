<?php
declare(strict_types=1);

namespace Kernel\Module;

/**
 * Minimal identity boundary for a business domain participating in the runtime.
 * Optional runtime behavior is exposed through capability-specific contracts.
 */
interface DomainModuleInterface
{
    /** Lowercase technical key, for example "sales". */
    public function name(): string;
}
