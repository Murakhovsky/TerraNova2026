<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Exception;

use RuntimeException;

final class MarketDataNormalizationException extends RuntimeException
{
    public function __construct(public readonly string $reasonCode,string $message)
    {
        parent::__construct($message);
    }
}
