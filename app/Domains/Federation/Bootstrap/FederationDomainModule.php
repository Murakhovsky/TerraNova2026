<?php
declare(strict_types=1);

namespace Domains\Federation\Bootstrap;

use Kernel\Action\Contract\ActionHandlerInterface;
use Kernel\Module\Contract\ActionOwningModuleInterface;
use Kernel\Module\DomainModuleInterface;

/**
 * Explicitly installed Federation adapter; disabled for all tenants by default.
 * No duplicate orchestration engine, only a registered canonical Action owner.
 */
final readonly class FederationDomainModule implements DomainModuleInterface, ActionOwningModuleInterface
{
    public function __construct(private ActionHandlerInterface $approvalHandler) {}

    public function name(): string { return 'federation'; }

    /** @return list<string> */
    public function actionTypes(): array { return ['cos.federation.plan.approval']; }

    /** @return list<ActionHandlerInterface> */
    public function actionHandlers(): array { return [$this->approvalHandler]; }
}
