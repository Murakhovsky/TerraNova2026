<?php
declare(strict_types=1);

namespace Kernel\Module\Contract;

use Kernel\Rule\Contract\RuleContextProviderInterface;

interface EventOwningModuleInterface
{
    /** @return list<string> */
    public function eventTypes(): array;

    public function ruleContextProvider(): RuleContextProviderInterface;
}
