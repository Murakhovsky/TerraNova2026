<?php
declare(strict_types=1);

namespace App\Application\CapitalMarkets\Handler;

use App\Application\CapitalMarkets\Command\SampleBStockBboQuotes;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsAccessControlInterface;
use Domains\CapitalMarkets\Application\Service\BoundedBboSweepService;
use Domains\CapitalMarkets\Model\CapitalMarketsCapability;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use RuntimeException;

#[AsMessageHandler]
final readonly class SampleBStockBboQuotesHandler
{
    public function __construct(
        private BoundedBboSweepService $sweep,
        private CapitalMarketsAccessControlInterface $access,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(SampleBStockBboQuotes $message):void
    {
        if ($message->organizationId==='' || $message->actorId<1) {
            throw new RuntimeException('Bounded BBO sweep requires tenant and operator.');
        }
        if (!$this->access->hasCapability($message->organizationId,$message->actorId,CapitalMarketsCapability::MarketDataManage->value)
            && !$this->access->hasCapability($message->organizationId,$message->actorId,CapitalMarketsCapability::Manage->value)) {
            throw new RuntimeException('Bounded BBO sweep actor lacks MarketDataManage capability.');
        }
        $result=$this->sweep->run($message->organizationId,3,10,$message->intervalSeconds);
        $this->logger->info('Bounded public BBO sweep finished.',[
            'organization_id'=>$message->organizationId,'summary'=>$result,
        ]);
    }
}
