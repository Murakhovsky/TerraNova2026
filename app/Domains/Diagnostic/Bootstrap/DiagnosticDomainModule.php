<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Bootstrap;

use Kernel\Action\Contract\ActionHandlerInterface;
use Kernel\Module\Contract\ActionOwningModuleInterface;
use Kernel\Module\DomainModuleInterface;

final readonly class DiagnosticDomainModule implements DomainModuleInterface, ActionOwningModuleInterface
{
    /** @param list<ActionHandlerInterface> $handlers */
    public function __construct(private array $handlers = []) {}
    public function name(): string { return 'diagnostic'; }
    public function actionTypes(): array { return ['IMPLEMENT_DIAGNOSTIC_RECOMMENDATION']; }
    public function actionHandlers(): array { return $this->handlers; }
}
