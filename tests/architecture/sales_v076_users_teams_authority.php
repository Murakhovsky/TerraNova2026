<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$contracts = [
    ['app/migrations/20260911_000034_sales_v076_users_teams_authority.sql', 'CREATE TABLE IF NOT EXISTS sales_teams'],
    ['app/migrations/20260911_000034_sales_v076_users_teams_authority.sql', 'sales_user_capabilities'],
    ['app/Kernel/Approval/Contract/ApprovalAuthorityInterface.php', 'assertCanDecide'],
    ['app/Kernel/Approval/Service/ApprovalService.php', '$this->authority?->assertCanDecide'],
    ['app/Domains/Sales/Application/Contract/SalesAssignmentAuthorityInterface.php', 'assertCanAssign'],
    ['app/Domains/Sales/Application/UseCase/AssignDealOwner.php', '$this->authority?->assertCanAssign'],
    ['app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlSalesApprovalAuthority.php', 'SalesCapability::ApprovalAnyTeam'],
    ['app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlSalesAssignmentAuthority.php', 'SalesCapability::DealAssign'],
    ['app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlSalesAccessControl.php', 'SalesAccessControlInterface'],
    ['app/Interfaces/Web/Routing/SalesTeamRoutes.php', '/sales/admin/teams'],
    ['app/Interfaces/Web/View/sales_admin/teams.phtml', 'Users, Teams & Authority'],
    ['app/Bootstrap/SalesAuthorityServices.php', 'salesApprovalAuthority'],
    ['app/config/services_kernel.php', 'SalesAuthorityServices.php'],
];
foreach ($contracts as [$file, $needle]) {
    $text = file_get_contents($root . '/' . $file);
    if ($text === false || !str_contains($text, $needle)) {
        throw new RuntimeException("Missing V0.7.6 contract in {$file}: {$needle}");
    }
}

$migration = file_get_contents($root . '/app/migrations/20260911_000034_sales_v076_users_teams_authority.sql');
if ($migration === false) throw new RuntimeException('V0.7.6 migration is missing.');
foreach (['sales_teams', 'sales_team_members', 'sales_user_capabilities'] as $table) {
    if (!str_contains($migration, $table)) throw new RuntimeException("Missing {$table} in V0.7.6 migration.");
}
if (preg_match('/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+sales_users\b/i', $migration)) {
    throw new RuntimeException('V0.7.6 must reuse tn_users/cos_organization_memberships and must not create sales_users.');
}

$approval = file_get_contents($root . '/app/Kernel/Approval/Service/ApprovalService.php');
$authorityAt = strpos((string) $approval, '$this->authority?->assertCanDecide');
$decisionAt = strpos((string) $approval, '$this->approvals->decide');
if ($authorityAt === false || $decisionAt === false || $authorityAt > $decisionAt) {
    throw new RuntimeException('Approval authority must be enforced before Approval state mutation.');
}

$assignment = file_get_contents($root . '/app/Domains/Sales/Application/UseCase/AssignDealOwner.php');
$authorityAt = strpos((string) $assignment, '$this->authority?->assertCanAssign');
$mutationAt = strpos((string) $assignment, '$this->deals->assignOwner');
if ($authorityAt === false || $mutationAt === false || $authorityAt > $mutationAt) {
    throw new RuntimeException('Assignment authority must be enforced before Deal owner mutation.');
}

$capabilities = file_get_contents($root . '/app/Domains/Sales/Model/SalesCapability.php');
foreach (['sales.deal.assign', 'sales.approval.decide', 'sales.approval.any_team', 'sales.admin.teams.manage'] as $capability) {
    if (!str_contains((string) $capabilities, $capability)) throw new RuntimeException("Missing capability {$capability}.");
}

echo "Sales V0.7.6 users/teams/authority architecture: OK\n";
