<?php
declare(strict_types=1);

namespace Domains\Sales\Model;

enum SalesCapability: string
{
    use HasStringValues;

    case WorkspaceUse = 'sales.workspace.use';
    case DirectorView = 'sales.director.view';
    case DealAssign = 'sales.deal.assign';
    case ApprovalDecide = 'sales.approval.decide';
    case ApprovalAnyTeam = 'sales.approval.any_team';

    case AdminView = 'sales.admin.view';
    case AdminPipelineManage = 'sales.admin.pipeline.manage';
    case AdminRulesManage = 'sales.admin.rules.manage';
    case AdminAgentsManage = 'sales.admin.agents.manage';
    case AdminPoliciesManage = 'sales.admin.policies.manage';
    case AdminTeamsManage = 'sales.admin.teams.manage';
    case AdminIntegrationsManage = 'sales.admin.integrations.manage';
    case AdminAuditView = 'sales.admin.audit.view';
}
