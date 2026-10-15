<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\L2;

use Domains\CapitalMarkets\Domain\MarketData\MarketOrderBook;
use Domains\CapitalMarkets\Domain\Service\L2RecoverySession;

/** Publication boundary: stale or unverified books never escape. */
final readonly class L2TrustedBookGate
{
    public function __construct(private L2RecoverySession $session) {}

    public function get(int $epoch): ?MarketOrderBook
    {
        return $this->session->trustedBook($epoch);
    }

    /** @return array{status:string,epoch:int,version:int,sequence:?string,failure:?string,buffered:int} */
    public function diagnostics(): array
    {
        return $this->session->diagnostics();
    }
}
