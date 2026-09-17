<?php
declare(strict_types=1);

namespace Kernel\Agent\Model;

final readonly class AgentOutput
{
    public function __construct(
        public string $content = '',
        public array $structured = [],
        public ?string $provider = null,
        public ?string $model = null,
        public array $usage = [],
        public array $metadata = [],
    ) {}
}
