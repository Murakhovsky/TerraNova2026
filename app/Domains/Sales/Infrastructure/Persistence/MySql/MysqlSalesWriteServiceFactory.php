<?php
declare(strict_types=1);

namespace Domains\Sales\Infrastructure\Persistence\MySql;

use Domains\Property\Contract\PropertyReferencePort;
use Domains\Sales\Application\Contract\SalesAssignmentAuthorityInterface;
use Domains\Sales\Application\Contract\SalesWriteServiceFactoryInterface;
use Domains\Sales\Application\Contract\SalesWriteServiceInterface;
use Domains\Sales\Application\Service\ClientCaseCommandService;
use Domains\Sales\Application\Service\SalesInboundService;
use Domains\Sales\Application\Service\SalesWriteService;
use Domains\Sales\Application\UseCase\AssignDealOwner;
use Domains\Sales\Application\UseCase\ChangeDealStage;
use Domains\Sales\Application\UseCase\CompleteSalesCall;
use Domains\Sales\Application\UseCase\ScheduleDealFollowup;
use Domains\Sales\Domain\Policy\StageTransitionPolicy;
use Domains\Sales\Infrastructure\Property\SalesPropertyReference;
use Domains\Sales\Infrastructure\ReadModel\MySql\MysqlClientCaseReadModel;
use Infrastructure\Platform\Persistence\MySql\Event\MysqlEventStore;
use Infrastructure\Platform\Persistence\MySql\MysqlExternalReferenceStore;
use Infrastructure\Platform\Persistence\MySql\Transaction\TransactionManager;
use InvalidArgumentException;
use Kernel\Event\EventBus;
use PDO;

final readonly class MysqlSalesWriteServiceFactory implements SalesWriteServiceFactoryInterface
{
    public function __construct(
        private PDO $connection,
        private PropertyReferencePort $properties,
        private SalesAssignmentAuthorityInterface $assignmentAuthority,
    ) {
    }

    public function forOrganization(string $organizationId): SalesWriteServiceInterface
    {
        $organizationId = trim($organizationId);
        if ($organizationId === '') {
            throw new InvalidArgumentException('organizationId is required.');
        }

        $transactions = new TransactionManager($this->connection);
        $events = new EventBus(new MysqlEventStore($this->connection), $transactions);
        $propertyReference = new SalesPropertyReference($this->properties, $organizationId);
        $commands = new MysqlClientCaseCommandRepository($this->connection, $propertyReference);
        $readModel = new MysqlClientCaseReadModel($this->connection, $organizationId, $propertyReference);
        $pipelines = new MysqlPipelineRepository($this->connection);
        $deals = new MysqlDealRepository($this->connection);
        $stages = new ChangeDealStage($deals, $pipelines, new StageTransitionPolicy(), $events, $transactions);
        $owners = new AssignDealOwner($deals, $events, $transactions, $this->assignmentAuthority);
        $completeCall = new CompleteSalesCall(new MysqlSalesActivityRepository($this->connection), $events, $transactions);
        $cases = new ClientCaseCommandService(
            $readModel,
            $commands,
            $events,
            $transactions,
            $organizationId,
            $completeCall,
            $pipelines,
            $stages,
            $owners,
        );
        $inbound = new SalesInboundService(
            $readModel,
            $commands,
            $events,
            $transactions,
            $organizationId,
            $pipelines,
            $stages,
        );
        $followups = new ScheduleDealFollowup(
            new MysqlFollowupRepository($this->connection, new MysqlExternalReferenceStore($this->connection)),
            $events,
            $transactions,
        );

        return new SalesWriteService(
            $organizationId,
            new MysqlInboundLeadRepository($this->connection),
            $commands,
            $inbound,
            $cases,
            $stages,
            $followups,
            new MysqlSalesMutationReceiptRepository($this->connection),
            $events,
            $transactions,
        );
    }
}
