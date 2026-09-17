<?php
declare(strict_types=1);

namespace Platform\Knowledge\Contract;

use Platform\Knowledge\Model\ContextRequest;
use Platform\Knowledge\Model\RetrievalResult;

interface RetrieverInterface
{
    /** @return list<RetrievalResult> */
    public function retrieve(ContextRequest $request): array;
}
