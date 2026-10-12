<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Exception;

use DomainException;

/** An active streaming lease owns this source's writes. Do not degrade health. */
final class MarketSourceStreamingActiveException extends DomainException
{
}
