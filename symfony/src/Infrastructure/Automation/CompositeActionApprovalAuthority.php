<?php
declare(strict_types=1);

namespace App\Infrastructure\Automation;

use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesApprovalAuthority;
use Domains\Finance\Infrastructure\Persistence\MySql\MysqlFinanceOrderApprovalAuthority;
use Kernel\Action\Action;
use Kernel\Approval\Approval;
use Kernel\Approval\Contract\ApprovalAuthorityInterface;

/**
 * One approval authority entrypoint. No Domain bypass and no direct
 * Approval table writes. Each domain-owned policy stays isolated.
 */
final readonly class CompositeActionApprovalAuthority implements ApprovalAuthorityInterface
{
    public function __construct(
        private MysqlSalesApprovalAuthority $sales,
        private FederationPlanApprovalAuthority $federation,
        private ?MysqlFinanceOrderApprovalAuthority $finance=null,
    ) {}

    public function assertCanDecide(
        string $organizationId,
        string $userId,
        Approval $approval,
        Action $action,
    ): void {
        $this->sales->assertCanDecide($organizationId,$userId,$approval,$action);
        $this->federation->assertCanDecide($organizationId,$userId,$approval,$action);
        $this->finance?->assertCanDecide($organizationId,$userId,$approval,$action);
    }
}
