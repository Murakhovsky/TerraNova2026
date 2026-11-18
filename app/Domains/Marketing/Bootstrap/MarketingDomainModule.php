<?php
declare(strict_types=1);
namespace Domains\Marketing\Bootstrap;
use Kernel\Module\DomainModuleInterface;
/** Uses canonical Kernel actions; never owns a separate executor. */
final readonly class MarketingDomainModule implements DomainModuleInterface {
 public function name():string{return 'marketing';}
}
