<?php
declare(strict_types=1);

use Domains\Sales\Application\UseCase\AssignDealOwner;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesAccessControl;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesApprovalAuthority;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesAssignmentAuthority;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesTeamAdministration;
use Kernel\Approval\Service\ApprovalService;

$di->setShared('salesAccessControl', fn (): MysqlSalesAccessControl => new MysqlSalesAccessControl(
    $this->getShared('databaseService')->connection(),
));
$di->setShared('salesTeamAdministration', fn (): MysqlSalesTeamAdministration => new MysqlSalesTeamAdministration(
    $this->getShared('databaseService')->connection(),
));
$di->setShared('salesAssignmentAuthority', fn (): MysqlSalesAssignmentAuthority => new MysqlSalesAssignmentAuthority(
    $this->getShared('databaseService')->connection(),
));
$di->setShared('salesApprovalAuthority', fn (): MysqlSalesApprovalAuthority => new MysqlSalesApprovalAuthority(
    $this->getShared('databaseService')->connection(),
));

// V0.7.6 adds the authority boundary without replacing the existing Sales use case.
$di->setShared('salesAssignDealOwner', fn (): AssignDealOwner => new AssignDealOwner(
    $this->getShared('salesDealRepository'),
    $this->getShared('eventBus'),
    $this->getShared('cosTransactionManager'),
    $this->getShared('salesAssignmentAuthority'),
));

// Approval execution remains Kernel-owned; Sales contributes only the authority provider.
$di->setShared('cosApprovalService', fn (): ApprovalService => new ApprovalService(
    $this->getShared('cosApprovalRepository'),
    $this->getShared('cosActionService'),
    $this->getShared('cosTransactionManager'),
    $this->getShared('cosAuditRepository'),
    $this->getShared('cosJobQueue'),
    $this->getShared('salesApprovalAuthority'),
));
