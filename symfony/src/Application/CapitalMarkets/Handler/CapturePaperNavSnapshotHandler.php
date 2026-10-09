<?php
declare(strict_types=1);

namespace App\Application\CapitalMarkets\Handler;

use App\Application\CapitalMarkets\Command\CapturePaperNavSnapshot;
use Domains\CapitalMarkets\Application\Service\PaperNavSnapshotService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CapturePaperNavSnapshotHandler
{
    public function __construct(
        private PaperNavSnapshotService $snapshots,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(CapturePaperNavSnapshot $message):void
    {
        $result=$this->snapshots->snapshot($message->organizationId,$message->portfolioId);
        if (($result['status']??'')!=='SIMULATED') {
            // A missing market mark is expected while markets are closed:
            // do not endlessly retry the identical scheduler event.
            $this->logger->warning('Paper NAV unavailable; no snapshot written.',[
                'organization_id'=>$message->organizationId,
                'portfolio_id'=>$message->portfolioId,
                'reasons'=>$result['reasons']??['UNKNOWN'],
            ]);
        }
    }
}
