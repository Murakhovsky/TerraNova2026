<?php

declare(strict_types=1);

namespace App\Web\Experience\Extension\Provider;

use App\Web\Experience\Action\UIAction;
use App\Web\Experience\Action\UIActionConfirmation;
use App\Web\Experience\Action\UIActionDangerLevel;
use App\Web\Experience\Action\UIActionIntent;
use App\Web\Experience\Action\UIActionPlacement;
use App\Web\Experience\Extension\Contract\ActionProviderInterface;
use App\Web\Experience\Extension\Contract\EntityLinkProviderInterface;
use App\Web\Experience\Extension\Model\EntityLink;
use App\Web\Experience\Unified\Business\BusinessRecordProvider;
use App\Web\Experience\Unified\Business\WorkspaceCapabilityRead;
use App\Web\Experience\Extension\Contract\CommandProviderInterface;
use App\Web\Experience\Extension\Contract\NavigationProviderInterface;
use App\Web\Experience\Extension\Contract\SearchProviderInterface;
use App\Web\Experience\Extension\Contract\WorkspaceProviderInterface;
use App\Web\Experience\Extension\Contract\WorkspaceExtensionProviderInterface;
use App\Web\Experience\Extension\Model\NavigationContribution;
use App\Web\Experience\Extension\Model\SearchResult;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Extension\Model\WorkspaceDefinition;
use App\Web\Experience\Extension\Model\WorkspaceExtension;
use App\Web\Experience\Model\EntityRef;
use App\Web\Experience\Search\SearchResultMatcher;
use App\Web\Experience\Shell\ShellCommandItem;
use App\Web\Experience\Workspace\WorkspaceSlot;
use App\Application\Experience\Search\Contract\SalesEntitySearchInterface;

final class SalesWebProvider implements NavigationProviderInterface, SearchProviderInterface, CommandProviderInterface, WorkspaceProviderInterface, WorkspaceExtensionProviderInterface, ActionProviderInterface, BusinessRecordProvider, EntityLinkProviderInterface
{
    public function __construct(
        private readonly SearchResultMatcher $matcher,
        private readonly SalesEntitySearchInterface $entitySearch,
        private readonly WorkspaceCapabilityRead $read,
    ) {
    }

    public function serviceId(): string
    {
        return 'salesNavigationContributor';
    }

    public function navigation(WebExtensionContext $context): array
    {
        $items = [
            new NavigationContribution('sales', 'Sales', '/sales/dashboard', 'SL', 20),
            new NavigationContribution('sales-overview', 'Overview', '/sales/dashboard', priority: 10, parentKey: 'sales'),
            new NavigationContribution('today', 'Today', '/sales/today', priority: 20, parentKey: 'sales'),
            new NavigationContribution('pipeline', 'Pipeline', '/sales/pipeline', priority: 30, parentKey: 'sales'),
            new NavigationContribution('leads', 'Leads', '/sales/leads', priority: 40, parentKey: 'sales'),
            new NavigationContribution('deals', 'Deals', '/sales/deals', priority: 50, parentKey: 'sales'),
            new NavigationContribution('director', 'Director', '/sales/director', priority: 60, parentKey: 'sales'),
            new NavigationContribution('sales-orders','Orders',WorkspaceCapabilityRead::workspacePath('sales.order'),priority:55,parentKey:'sales'),
            new NavigationContribution('clients', 'Clients', '/client-case/inbox', 'CL', 30),
            new NavigationContribution('inbox', 'Inbox', '/client-case/inbox', priority: 10, parentKey: 'clients'),
            new NavigationContribution('cases', 'Cases', '/client-case', priority: 20, parentKey: 'clients'),
        ];

        if ($context->role === 'admin') {
            $items[] = new NavigationContribution(
                'sales-admin',
                'Sales Admin',
                '/sales/admin',
                priority: 70,
                parentKey: 'sales',
            );
        }

        return $items;
    }

    public function search(WebExtensionContext $context, string $query, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $navigation = $this->matcher->match([
            new SearchResult('sales.search.overview', 'Sales Overview', '/sales/dashboard', 'workspace', 'Sales dashboard'),
            new SearchResult('sales.search.today', 'Sales Today', '/sales/today', 'workspace', 'Today queue'),
            new SearchResult('sales.search.pipeline', 'Sales Pipeline', '/sales/pipeline', 'workspace', 'Pipeline'),
            new SearchResult('sales.search.leads', 'Lead Workspace', '/sales/leads', 'workspace', 'Sales leads'),
            new SearchResult('sales.search.deals', 'Deal Workspace', '/sales/deals', 'workspace', 'Sales deals'),
            new SearchResult('sales.search.orders','Orders',WorkspaceCapabilityRead::workspacePath('sales.order'),'workspace','Order lookup'),
            new SearchResult('sales.search.clients', 'Client Inbox', '/client-case/inbox', 'workspace', 'Clients'),
            new SearchResult('sales.search.director', 'Sales Director', '/sales/director', 'workspace', 'Sales analytics'),
        ], $query, $limit);

        $query = trim($query);
        if ($query === '') {
            return $navigation;
        }

        if (preg_match('/^[a-f0-9]{32}$/D',$query)) {
            try {
                $order=$this->record($context,'sales.order',new EntityRef('sales.order',$query));
                if ($order!==null) return array_slice([$order['hit'],...$navigation],0,$limit);
            } catch (\Throwable) {
                // Inaccessible and nonexistent orders are indistinguishable.
            }
        }
        $entities = array_map(
            static fn ($hit): SearchResult => new SearchResult(
                id: $hit->id,
                label: $hit->label,
                path: $hit->path,
                kind: 'entity',
                subtitle: $hit->subtitle,
                entity: new EntityRef($hit->entityType, $hit->entityId),
                score: $hit->score,
            ),
            $this->entitySearch->search($context->organizationId, $query, $limit),
        );

        return array_slice([...$entities, ...$navigation], 0, $limit);
    }

    public function commands(WebExtensionContext $context): array
    {
        $commands = [
            new ShellCommandItem('sales.open', 'Open Sales Overview', '/sales/dashboard', 'navigation', 'Sales'),
            new ShellCommandItem('sales.pipeline', 'Open Sales Pipeline', '/sales/pipeline', 'navigation', 'Sales'),
            new ShellCommandItem('sales.leads', 'Open Leads', '/sales/leads', 'navigation', 'Sales'),
            new ShellCommandItem('sales.clients', 'Open Clients', '/client-case/inbox', 'navigation', 'Sales'),
        ];

        if ($context->role === 'admin') {
            $commands[] = new ShellCommandItem('sales.admin', 'Open Sales Administration', '/sales/admin', 'navigation', 'Admin');
        }

        return $commands;
    }


    public function actions(WebExtensionContext $context, ?EntityRef $entity = null): array
    {
        if ($entity === null) {
            return [];
        }

        if ($entity->type==='sales.order'&&preg_match('/^[a-f0-9]{32}$/D',$entity->id)) {
            $available=$this->read->offersHumanCommand($context,'sales.order.lifecycle.confirm');
            return [new UIAction(
                id:'sales.order.confirm',label:'Confirm order',intent:UIActionIntent::Execute,
                permission:'sales.workspace.use',enabled:$available,
                disabledReason:$available?null:'Canonical human command binding unavailable.',
                confirmation:UIActionConfirmation::simple('Confirm the native Sales order only; no payment or fulfillment is executed.','Confirm order'),
                command:'sales.order.lifecycle.confirm',dangerLevel:UIActionDangerLevel::Caution->value,
                placements:[UIActionPlacement::WORKSPACE_PRIMARY,UIActionPlacement::MOBILE_PRIMARY],
            )];
        }

        if (in_array($entity->type, ['deal', 'sales.deal'], true)) {
            return [
                new UIAction(
                    id: 'sales.deal.change_stage',
                    label: 'Change stage',
                    intent: UIActionIntent::Execute,
                    icon: 'arrows-exchange',
                    permission: 'sales.workspace.use',
                    confirmation: UIActionConfirmation::simple(
                        'Apply the selected stage transition to this deal?',
                        'Change stage',
                    ),
                    command: 'sales.change_stage',
                    dangerLevel: UIActionDangerLevel::Caution->value,
                    priority: 10,
                    placements: [
                        UIActionPlacement::WORKSPACE_PRIMARY,
                        UIActionPlacement::CONTEXT_MENU,
                        UIActionPlacement::MOBILE_PRIMARY,
                        UIActionPlacement::AI_PROPOSAL,
                    ],
                ),
                new UIAction(
                    id: 'sales.deal.assign_owner',
                    label: 'Assign owner',
                    intent: UIActionIntent::Execute,
                    icon: 'user-check',
                    permission: 'sales.deal.assign',
                    command: 'sales.assign_owner',
                    priority: 20,
                    placements: [
                        UIActionPlacement::WORKSPACE_SECONDARY,
                        UIActionPlacement::CONTEXT_MENU,
                        UIActionPlacement::MOBILE_MENU,
                        UIActionPlacement::AI_PROPOSAL,
                    ],
                ),
                new UIAction(
                    id: 'sales.deal.request_document',
                    label: 'Request document',
                    intent: UIActionIntent::Execute,
                    icon: 'file-plus',
                    permission: 'sales.workspace.use',
                    command: 'sales.request_document',
                    async: true,
                    priority: 30,
                    placements: [
                        UIActionPlacement::WORKSPACE_SECONDARY,
                        UIActionPlacement::CONTEXT_MENU,
                        UIActionPlacement::MOBILE_MENU,
                        UIActionPlacement::AI_PROPOSAL,
                    ],
                ),
            ];
        }

        if (in_array($entity->type, ['lead', 'sales.lead'], true)) {
            return [
                new UIAction(
                    id: 'sales.lead.create_followup',
                    label: 'Create follow-up',
                    intent: UIActionIntent::Execute,
                    icon: 'calendar-plus',
                    permission: 'sales.workspace.use',
                    command: 'sales.create_lead_followup_task',
                    priority: 10,
                    placements: [
                        UIActionPlacement::WORKSPACE_PRIMARY,
                        UIActionPlacement::CONTEXT_MENU,
                        UIActionPlacement::MOBILE_PRIMARY,
                        UIActionPlacement::AI_PROPOSAL,
                    ],
                ),
            ];
        }

        return [];
    }


    public function extensions(WebExtensionContext $context, string $workspaceId): array
    {
        if ($workspaceId==='sales.order') {
            return [new WorkspaceExtension($workspaceId,WorkspaceSlot::Sidebar,'experience/unified/business/sales_order_command.html.twig',10)];
        }
        if (!in_array($workspaceId, ['sales.lead', 'sales.deal', 'sales.client_case'], true)) {
            return [];
        }

        return [
            new WorkspaceExtension(
                $workspaceId,
                WorkspaceSlot::Sidebar,
                'experience/workspace/extensions/sales/context.html.twig',
                10,
            ),
            new WorkspaceExtension(
                $workspaceId,
                WorkspaceSlot::Activity,
                'experience/workspace/extensions/sales/activity.html.twig',
                20,
            ),
            new WorkspaceExtension(
                $workspaceId,
                WorkspaceSlot::Ai,
                'experience/workspace/extensions/sales/ai_context.html.twig',
                30,
            ),
        ];
    }

    public function workspaces(WebExtensionContext $context): array
    {
        return [
            new WorkspaceDefinition('sales.lead', 'Lead Workspace', '/sales/leads', 'sales.lead', 10),
            new WorkspaceDefinition('sales.deal', 'Deal Workspace', '/sales/deals', 'sales.deal', 20),
            new WorkspaceDefinition('sales.order','Orders',WorkspaceCapabilityRead::workspacePath('sales.order'),'sales.order',25),
            new WorkspaceDefinition('sales.client_case', 'Client Case Workspace', '/client-case', 'sales.deal', 30),
        ];
    }

    public function supportsRecords(string $workspaceId):bool { return $workspaceId==='sales.order'; }

    public function records(WebExtensionContext $context,string $workspaceId,string $query,int $page):array
    {
        if (!$this->supportsRecords($workspaceId)) throw new \InvalidArgumentException('Unsupported workspace.');
        $notice='Order lookup requires the exact COS order reference. No unrestricted order-list Query is registered.';
        if (!preg_match('/^[a-f0-9]{32}$/D',$query)) return ['items'=>[],'has_more'=>false,'notice'=>$notice];
        $detail=$this->record($context,$workspaceId,new EntityRef('sales.order',$query));
        return ['items'=>$detail===null?[]:[$detail['hit']],'has_more'=>false,'notice'=>$notice];
    }

    public function record(WebExtensionContext $context,string $workspaceId,EntityRef $entity):?array
    {
        if (!$this->supportsRecords($workspaceId)||$entity->type!=='sales.order'||!preg_match('/^[a-f0-9]{32}$/D',$entity->id)) return null;
        $data=$this->read->query($context,'sales.order.read',['order_id'=>$entity->id]);
        $row=$data['order']??null;
        if (!is_array($row)||($row['order_id']??null)!==$entity->id) return null;
        $tables=[WorkspaceCapabilityRead::table('Order lines',['sku'=>'SKU','quantity'=>'Quantity','unit_price'=>'Unit price'],$row['lines']??[],'First 50 lines.')];
        $financial=$data['financial_evidence']??null;
        $facts=WorkspaceCapabilityRead::facts($row,['status'=>'Status','channel'=>'Source','currency'=>'Currency','total'=>'Order total','revision'=>'Revision','placed_at'=>'Placed']);
        if (is_array($financial)) $facts=[...$facts,...WorkspaceCapabilityRead::facts($financial,['net_collected_amount'=>'Reconciled net collected','outstanding_amount'=>'Outstanding','evidence_reference'=>'Finance evidence'])];
        else $facts[]=['label'=>'Finance evidence','value'=>'Not available; payment not confirmed.'];
        return [
            'hit'=>WorkspaceCapabilityRead::hit($entity->type,$entity->id,'Order '.$entity->id,WorkspaceCapabilityRead::workspacePath($workspaceId,$entity),(string)$row['status']),
            'facts'=>$facts,'tables'=>$tables,
        ];
    }

    public function link(WebExtensionContext $context,EntityRef $entity):?EntityLink
    {
        if ($entity->type==='sales.order'&&preg_match('/^[a-f0-9]{32}$/D',$entity->id)) return new EntityLink('Order',WorkspaceCapabilityRead::workspacePath('sales.order',$entity));
        if ($entity->type==='sales.deal'&&preg_match('/^[1-9][0-9]{0,9}$/D',$entity->id)) return new EntityLink('Deal','/sales/deals/'.$entity->id);
        return null;
    }

}
