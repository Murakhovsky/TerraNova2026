<?php
declare(strict_types=1);

namespace Kernel\Action\Contract;

use Kernel\Action\Action;

/** Tenant-authoritative worker admission of Federation-prefixed Actions. */
interface FederatedActionAdmissionInterface
{
    public function assertAuthorized(Action $action): void;
}
