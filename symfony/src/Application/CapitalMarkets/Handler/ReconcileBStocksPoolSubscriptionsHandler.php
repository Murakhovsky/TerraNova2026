<?php
declare(strict_types=1);
namespace App\Application\CapitalMarkets\Handler;
use App\Application\CapitalMarkets\Command\ReconcileBStocksPoolSubscriptions;
use Domains\CapitalMarkets\Application\Service\BStocksPoolSubscriptionReconciler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ReconcileBStocksPoolSubscriptionsHandler
{
    public function __construct(
        private BStocksPoolSubscriptionReconciler $planner,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(ReconcileBStocksPoolSubscriptions $message):void
    {
        $result=$this->planner->reconcile($message->organizationId,$message->actorId,100);
        $this->logger->info('BStocks scheduled subscription intents evaluated.',[
            'organization_id'=>$message->organizationId,'result'=>$result,
        ]);
    }
}
