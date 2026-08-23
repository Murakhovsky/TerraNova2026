<?php
declare(strict_types=1);

namespace Kernel\Rule;

use Kernel\Action\ActionProposal;

final readonly class ProcessEvaluation
{
    public function __construct(
        public Rule $rule,
        public bool $matched,
        public ?ActionProposal $proposal,
        /** @var list<ActionProposal> */
        public array $proposals = [],
    ) {
    }
}
