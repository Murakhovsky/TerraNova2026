<?php
declare(strict_types=1);

namespace Platform\Knowledge\Contract;

use Platform\Knowledge\Model\Context;
use Platform\Knowledge\Model\ContextRequest;

interface ContextBuilderInterface
{
    public function build(ContextRequest $request): Context;
}
