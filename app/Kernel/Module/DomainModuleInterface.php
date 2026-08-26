<?php
declare(strict_types=1);

namespace Kernel\Module;

use Kernel\Action\Contract\ActionHandlerInterface;
use Kernel\Agent\AgentDefinition;
use Kernel\Agent\Contract\AgentContextBuilderInterface;
use Kernel\Policy\ActionPolicy;
use Kernel\Rule\Contract\RuleContextProviderInterface;
use Kernel\Rule\Rule;

/**
 * Stable boundary through which a business domain contributes meaning to the Kernel.
 * Implementations may depend on Kernel contracts and their own domain contracts only.
 */
interface DomainModuleInterface
{
    /** Lowercase technical key, for example "sales". */
    public function name(): string;

    /** @return list<string> */
    public function eventTypes(): array;

    /** @return list<string> */
    public function actionTypes(): array;

    /** @return list<ActionHandlerInterface> */
    public function actionHandlers(): array;

    /** @return array<string, AgentDefinition> Indexed by agent name. */
    public function agents(): array;

    /** @return array<string, AgentContextBuilderInterface> Indexed by agent name. */
    public function agentContextBuilders(): array;

    public function ruleContextProvider(): RuleContextProviderInterface;

    /** @return list<Rule> */
    public function rules(string $organizationId): array;

    /** @return list<ActionPolicy> */
    public function policies(string $organizationId): array;
}
