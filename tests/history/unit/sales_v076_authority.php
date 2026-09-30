<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Domains\Sales\Application\Contract\SalesAccessControlInterface;
use Domains\Sales\Application\Contract\SalesAssignmentAuthorityInterface;
use Domains\Sales\Application\UseCase\AssignDealOwner;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesAccessControl;
use Domains\Sales\Model\SalesCapability;
use Kernel\Approval\Contract\ApprovalAuthorityInterface;
use Kernel\Approval\Service\ApprovalService;

$values = SalesCapability::values();
foreach ([
    'sales.deal.assign',
    'sales.approval.decide',
    'sales.approval.any_team',
    'sales.admin.teams.manage',
] as $capability) {
    if (!in_array($capability, $values, true)) throw new RuntimeException("Missing V0.7.6 capability {$capability}.");
}

$approvalMethod = new ReflectionMethod(ApprovalAuthorityInterface::class, 'assertCanDecide');
if ($approvalMethod->getNumberOfParameters() !== 4) throw new RuntimeException('ApprovalAuthorityInterface contract changed unexpectedly.');

$approvalConstructor = new ReflectionMethod(ApprovalService::class, '__construct');
$approvalParameters = $approvalConstructor->getParameters();
$approvalAuthority = end($approvalParameters);
if (!$approvalAuthority instanceof ReflectionParameter || !str_contains((string) $approvalAuthority->getType(), 'ApprovalAuthorityInterface')) {
    throw new RuntimeException('Kernel ApprovalService must receive the generic approval authority contract.');
}

$assignmentConstructor = new ReflectionMethod(AssignDealOwner::class, '__construct');
$assignmentParameters = $assignmentConstructor->getParameters();
$assignmentAuthority = end($assignmentParameters);
if (!$assignmentAuthority instanceof ReflectionParameter || !str_contains((string) $assignmentAuthority->getType(), 'SalesAssignmentAuthorityInterface')) {
    throw new RuntimeException('AssignDealOwner must receive Sales assignment authority.');
}

if (!is_subclass_of(MysqlSalesAccessControl::class, SalesAccessControlInterface::class)) {
    throw new RuntimeException('Sales access control implementation must satisfy the Application contract.');
}
if (!interface_exists(SalesAssignmentAuthorityInterface::class)) throw new RuntimeException('Sales assignment authority contract is missing.');

echo "Sales V0.7.6 authority contracts: OK\n";
