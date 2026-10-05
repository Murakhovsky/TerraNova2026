<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Bootstrap;

use Kernel\Module\DomainModuleInterface;

final class CapitalMarketsDomainModule implements DomainModuleInterface
{
    public function name():string{return 'capital_markets';}
}
