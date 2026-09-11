<?php
declare(strict_types=1);

namespace Kernel\Policy\Contract;

interface PolicyContextProvidingModuleInterface
{
    public function policyContextProvider(): PolicyContextProviderInterface;
}
