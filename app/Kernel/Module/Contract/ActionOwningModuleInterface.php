<?php
declare(strict_types=1);

namespace Kernel\Module\Contract;

use Kernel\Action\Contract\ActionHandlerInterface;

interface ActionOwningModuleInterface
{
    /** @return list<string> */
    public function actionTypes(): array;

    /** @return list<ActionHandlerInterface> */
    public function actionHandlers(): array;
}
