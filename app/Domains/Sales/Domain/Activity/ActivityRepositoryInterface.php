<?php
declare(strict_types=1);

namespace Domains\Sales\Domain\Activity;

interface ActivityRepositoryInterface
{
    public function record(Activity $activity): void;
}
