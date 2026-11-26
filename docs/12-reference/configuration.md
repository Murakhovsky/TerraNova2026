---
title: Довідник конфігурації
description: Згенерована карта ownership для module configuration provisioners і declarations можливостей.
status: generated
kind: reference
generated: true
---

<!-- ЗГЕНЕРОВАНИЙ ФАЙЛ: НЕ РЕДАГУВАТИ ВРУЧНУ. Запустіть `npm run docs:generate`. -->

# Довідник конфігурації

> Джерело істини: `app/Domains/*/module.php` у поточному checkout.

| Модуль | Версія | Configuration provisioners | Можливостей |
| --- | --- | --- | ---: |
| `capital_markets` | `0.9.0` | — | 39 |
| `construction` | `0.1.0` | — | 0 |
| `diagnostic` | `1.0.0` | — | 10 |
| `documents` | `0.1.0` | — | 3 |
| `federation` | `1.0.0` | — | 1 |
| `finance` | `0.2.2` | — | 16 |
| `growth` | `0.50.0` | — | 92 |
| `hr` | `0.1.0` | — | 0 |
| `marketing` | `0.2.3` | — | 62 |
| `procurement` | `0.1.0` | — | 0 |
| `product_management` | `0.1.0` | — | 94 |
| `property` | `1.0.0` | `propertyModuleConfigurationProvisioner` | 25 |
| `real_estate` | `1.0.0` | — | 6 |
| `sales` | `1.0.0` | `salesModuleConfigurationProvisioner` | 81 |
| `service` | `1.0.0` | — | 7 |

## `capital_markets`

- manifest: `app/Domains/CapitalMarkets/module.php`;
- configuration provisioners: —;
- capabilities: `capital_markets.research.result.record`, `capital_markets.view`, `capital_markets.manage`, `capital_markets.instrument.view`, `capital_markets.instrument.manage`, `capital_markets.instrument_pool.view`, `capital_markets.instrument_pool.propose`, `capital_markets.instrument_pool.approve`, `capital_markets.relationship.view`, `capital_markets.relationship.manage`, `capital_markets.venue.view`, `capital_markets.venue.manage`, `capital_markets.audit.view`, `capital_markets.market_data.view`, `capital_markets.market_data.manage`, `capital_markets.market_data.source.view`, `capital_markets.market_data.source.manage`, `capital_markets.market_data.quality.view`, `capital_markets.market_data.history.view`, `capital_markets.market_data.replay.manage`, `capital_markets.opportunity.view`, `capital_markets.paper.execute`, `capital_markets.research.view`, `capital_markets.research.manage`, `capital_markets.research.experiment.run`, `capital_markets.strategy.version.manage`, `capital_markets.strategy.promote`, `capital_markets.strategy.demote`, `capital_markets.strategy.reject`, `capital_markets.research.agent.use`, `capital_markets.portfolio.view`, `capital_markets.portfolio.manage`, `capital_markets.risk.view`, `capital_markets.risk.manage_policy`, `capital_markets.allocation.view`, `capital_markets.allocation.propose`, `capital_markets.allocation.approve`, `capital_markets.rebalance.propose`, `capital_markets.rebalance.approve`.

## `construction`

- manifest: `app/Domains/Construction/module.php`;
- configuration provisioners: —;
- capabilities: —.

## `diagnostic`

- manifest: `app/Domains/Diagnostic/module.php`;
- configuration provisioners: —;
- capabilities: `diagnostic.session.start`, `diagnostic.evidence.capture`, `diagnostic.session.complete`, `diagnostic.report.summary.get`, `diagnostic.recommendation.accept`, `diagnostic.methodology.compile`, `diagnostic.state.rebuild`, `diagnostic.traceability`, `diagnostic.semantic.v1`, `diagnostic.v1`.

## `documents`

- manifest: `app/Domains/Documents/module.php`;
- configuration provisioners: —;
- capabilities: `documents.signature.request`, `documents.signature.sign`, `documents.proposal.prepare`.

## `federation`

- manifest: `app/Domains/Federation/module.php`;
- configuration provisioners: —;
- capabilities: `federation.plan.approval`.

## `finance`

- manifest: `app/Domains/Finance/module.php`;
- configuration provisioners: —;
- capabilities: `finance.order.payment.register`, `finance.order.payment.reconcile`, `finance.order.financial_evidence.read`, `finance.order.payment.operations.read`, `finance.sales.commercial_evidence.read`, `finance.budget.envelope.draft.create`, `finance.budget.envelope.get`, `finance.budget.reservation.list`, `finance.budget.envelope.activate`, `finance.budget.reservation.reserve`, `finance.budget.reservation.release`, `finance.budget.envelope.freeze`, `finance.ad_spend.reconciliation.status`, `finance.ad_spend.settlement.commit`, `finance.ad_spend.settlement.status`, `finance.ad_spend.settlement.review`.

## `growth`

- manifest: `app/Domains/Growth/module.php`;
- configuration provisioners: —;
- capabilities: `growth.market.resumable_discovery`, `growth.market.partial_retry`, `growth.learning.conversation_binding`, `growth.market.workspace`, `growth.market.universe.manage`, `growth.market.discovery`, `growth.market.automated_sourcing`, `growth.market.monitoring`, `growth.market.opportunity_detection`, `growth.engagement.email_delivery_feedback`, `growth.engagement.email_complaint_suppression`, `growth.engagement.email_conversation_feedback`, `growth.engagement.conversation_routing`, `growth.engagement.contact_suppression`, `growth.engagement.routing_target.sales`, `growth.engagement.routing_target.service`, `growth.engagement.response_classification`, `growth.engagement.inbound_response`, `growth.engagement.response_webhook`, `growth.engagement.sequence_scheduler`, `growth.engagement.sequence_state_machine`, `growth.engagement.sequence_policy`, `growth.engagement.autonomous_content_scheduler`, `growth.engagement.autonomous_content_review`, `growth.engagement.autonomous_content_drafting`, `growth.engagement.autonomous_trigger`, `growth.engagement.autonomy_payload_staging`, `growth.engagement.autonomy_policy`, `growth.engagement.activation_policy`, `growth.engagement.atomic_capacity_admission`, `growth.engagement.channel_quotas`, `growth.engagement.limit_profile`, `growth.engagement.execution_limits`, `growth.engagement.delivery_feedback`, `growth.engagement.pre_handoff_linkedin_call_execution`, `growth.signal.polling_alerts`, `growth.signal.polling_incidents`, `growth.signal.polling_health`, `growth.signal.polling_workspace`, `growth.signal.scheduled_polling`, `growth.signal.json_source.workspace`, `growth.signal.collector.credentialed_json`, `growth.signal.json_source.manage`, `growth.signal.feed.workspace`, `growth.signal.collector.rss_atom`, `growth.signal.feed.manage`, `growth.handoff.target.service`, `growth.engagement.pre_handoff_execution`, `growth.engagement.execution_workspace`, `growth.engagement.execution`, `growth.experiments.decision`, `growth.experiments.workspace`, `growth.experiments`, `growth.attribution`, `growth.learning.optimization_workspace`, `growth.learning.optimize`, `growth.learning.workspace`, `growth.learning.feedback`, `growth.learning.brief`, `growth.engagement.intelligence`, `growth.signal.external_webhook`, `growth.workspace`, `growth.signal.operations`, `growth.api.v1`, `growth.handoff.target.sales`, `growth.handoff.dispatch`, `growth.handoff.brief`, `growth.handoff.targets`, `growth.research.accept`, `growth.research.brief`, `growth.research.generate`, `growth.qualification.policy`, `growth.candidate.evaluate`, `growth.candidate.decision_brief`, `growth.signal.collect`, `growth.signal.dedupe`, `growth.signal.ingest`, `growth.buying_committee.assess`, `growth.buying_committee.brief`, `growth.contact.discover`, `growth.contact.enrich`, `growth.icp.manage`, `growth.account.discover`, `growth.account.enrich`, `growth.account.score`, `growth.account.brief`, `growth.signal.detect`, `growth.candidate.research`, `growth.candidate.score`, `growth.candidate.qualify`, `growth.handoff.prepare`, `growth.candidate.monitor`.

## `hr`

- manifest: `app/Domains/HR/module.php`;
- configuration provisioners: —;
- capabilities: —.

## `marketing`

- manifest: `app/Domains/Marketing/module.php`;
- configuration provisioners: —;
- capabilities: `marketing.campaign.search`, `marketing.campaign.get`, `marketing.campaign.create`, `marketing.paid.account.list`, `marketing.paid.sync.history`, `marketing.paid.spend.evidence.get`, `marketing.paid.insights.query`, `marketing.product.draft_check.get`, `marketing.product.selector.search`, `marketing.creative.create`, `marketing.creative.revise`, `marketing.creative.list`, `marketing.analyst.context.get`, `marketing.analyst.run.list`, `marketing.analyst.run.review`, `marketing.analyst.run.recover`, `marketing.analyst.runtime.status`, `marketing.analyst.run.request`, `marketing.analyst.proposal.submit`, `marketing.analyst.proposal.list`, `marketing.analyst.proposal.review`, `marketing.audience.save`, `marketing.audience.list`, `marketing.adset.save`, `marketing.adset.list`, `marketing.campaign.update`, `marketing.campaign.archive`, `marketing.paid.sync.request`, `marketing.paid.change.request`, `marketing.paid.operation.status`, `marketing.paid.spend.compare`, `marketing.paid.launch_plan.create`, `marketing.paid.launch_plan.get`, `marketing.paid.funding.check`, `marketing.paid.meta.campaign.create_paused`, `marketing.paid.meta.campaign.activate`, `marketing.paid.meta.campaign.pause`, `marketing.attribution.capture`, `marketing.attribution.discover`, `marketing.attribution.tracking.get`, `marketing.analytics.report.get`, `marketing.analytics.media.report`, `marketing.analytics.media.sync`, `marketing.attribution.commerce.inspect`, `marketing.attribution.commerce.capture`, `marketing.analytics.commerce.report`, `marketing.attribution.sweep`, `marketing.attribution.journey.get`, `marketing.analytics.economics.report`, `marketing.analytics.monitor.get`, `marketing.optimization.budget.preview`, `marketing.optimization.audience.preview`, `marketing.experiment.register`, `marketing.experiment.get`, `marketing.optimization.creative.preview`, `marketing.optimization.rule.evaluate`, `marketing.optimization.rule.plan.propose`, `marketing.optimization.rule.review.record`, `marketing.optimization.learning.preview`, `marketing.optimization.learning.get`, `marketing.optimization.learning.plan.propose`, `marketing.optimization.learning.record`.

## `procurement`

- manifest: `app/Domains/Procurement/module.php`;
- configuration provisioners: —;
- capabilities: —.

## `product_management`

- manifest: `app/Domains/ProductManagement/module.php`;
- configuration provisioners: —;
- capabilities: `product_management.media.attach`, `product_management.media.detach`, `product_management.media.search`, `product_management.import.apply`, `product_management.shopify.import.apply`, `product_management.translation.upsert`, `product_management.stock.transfer`, `product_management.variant.update`, `product_management.variant.archive`, `product_management.category.create`, `product_management.category.assign`, `product_management.category.search`, `product_management.import.preview`, `product_management.import.batch.get`, `product_management.product.update`, `product_management.product.archive`, `product_management.stock.movement.search`, `product_management.variant.create`, `product_management.location.create`, `product_management.location.search`, `product_management.stock.reserve`, `product_management.stock.count.apply`, `product_management.stock.count.get`, `product_management.stock.count.session.apply`, `product_management.stock.count.session.get`, `product_management.stock.count.session.candidates`, `product_management.stock.reservation.search`, `product_management.stock.release`, `product_management.stock.receive`, `product_management.stock.issue`, `product_management.price.base.set`, `product_management.governed.product.create`, `product_management.governed.variant.matrix.create`, `product_management.governed.attribute.product.bulk_set`, `product_management.governed.price.base.set`, `product_management.governed.stock.receive`, `product_management.governed.stock.issue`, `product_management.governed.stock.reserve`, `product_management.governed.stock.release`, `product_management.governed.media.attach`, `product_management.governed.media.detach`, `product_management.governed.attribute.define`, `product_management.governed.attribute.category.bind`, `product_management.governed.attribute.product.set`, `product_management.governed.price.list.create`, `product_management.governed.price.list.update`, `product_management.governed.price.list.archive`, `product_management.governed.price.list.entry.remove`, `product_management.governed.price.list.tier.set`, `product_management.governed.category.create`, `product_management.governed.category.assign`, `product_management.governed.translation.upsert`, `product_management.governed.variant.create`, `product_management.governed.location.create`, `product_management.governed.product.update`, `product_management.governed.product.archive`, `product_management.governed.variant.update`, `product_management.governed.variant.archive`, `product_management.governed.stock.transfer`, `product_management.governed.price.list.entry.set`, `product_management.analytics.summary.get`, `product_management.analytics.stock.search`, `product_management.analytics.movement.search`, `product_management.analytics.quality.search`, `product_management.analytics.reconciliation.get`, `product_management.attribute.define`, `product_management.attribute.search`, `product_management.attribute.category.bind`, `product_management.attribute.category.get`, `product_management.attribute.product.set`, `product_management.attribute.product.bulk_set`, `product_management.attribute.product.get`, `product_management.attribute.product.filter`, `product_management.variant.matrix.preview`, `product_management.variant.matrix.create`, `product_management.product.create`, `product_management.product.get`, `product_management.product.search`, `product_management.stock.balance.get`, `product_management.price.base.get`, `product_management.price.history.search`, `product_management.price.list.create`, `product_management.price.list.search`, `product_management.price.list.entry.set`, `product_management.price.list.entry.get`, `product_management.price.list.update`, `product_management.price.list.archive`, `product_management.price.list.history.search`, `product_management.price.list.entry.remove`, `product_management.price.list.tier.set`, `product_management.price.list.tier.search`, `product_management.price.list.tier.history.search`, `product_management.price.resolve`, `product_management.sync.status.get`.

## `property`

- manifest: `app/Domains/Property/module.php`;
- configuration provisioners: `propertyModuleConfigurationProvisioner`;
- capabilities: `property.registry`, `property.read`, `property.write`, `property.intake`, `property.media`, `property.catalog`, `property.asset.create`, `property.asset.search`, `property.asset.get`, `property.asset.update`, `property.bulk.create`, `property.inventory`, `property.listing`, `property.publish`, `property.history`, `property.reference`, `property.analytics`, `property.intelligence`, `property.network`, `property.identity.review`, `property.runtime.canonical`, `property.api.v1`, `property.business.cutover`, `property.read.canonical`, `property.v1`.

## `real_estate`

- manifest: `app/Domains/RealEstate/module.php`;
- configuration provisioners: —;
- capabilities: `real_estate.brokerage`, `real_estate.property_match`, `real_estate.offer`, `real_estate.viewing`, `real_estate.reservation`, `real_estate.api.v1`.

## `sales`

- manifest: `app/Domains/Sales/module.php`;
- configuration provisioners: `salesModuleConfigurationProvisioner`;
- capabilities: `sales.governed.order.create`, `sales.governed.order.lifecycle.confirm`, `sales.governed.order.lifecycle.allocate`, `sales.governed.order.lifecycle.release`, `sales.governed.order.lifecycle.fulfill`, `sales.governed.order.lifecycle.cancel`, `sales.governed.order.lifecycle.return`, `sales.order.lifecycle.confirm`, `sales.order.lifecycle.allocate`, `sales.order.lifecycle.release`, `sales.order.lifecycle.fulfill`, `sales.order.lifecycle.cancel`, `sales.order.lifecycle.return`, `sales.order.lifecycle.read`, `sales.order.source.sync.start`, `sales.order.source.sync.status.read`, `sales.order.source.reconcile`, `sales.order.source.page.read`, `sales.order.source.pending.read`, `sales.order.create`, `sales.order.read`, `sales.workspace.team.summary.read`, `sales.reports.data.read`, `sales.analytics.outcomes.read`, `sales.analytics.forecast.read`, `sales.analytics.conversion.read`, `sales.analytics.stages.read`, `sales.workspace.leads.list`, `sales.workspace.deals.list`, `sales.workspace.deal.timeline.read`, `sales.workspace.summary.read`, `sales.offer.document.render`, `sales.offer.delivery.status`, `sales.offer.delivery.queue`, `sales.activity.reminder.queue`, `sales.crm.reconciliation.read`, `sales.lead.followup.schedule`, `sales.lead.activity.complete`, `sales.lead.activity.reschedule`, `sales.lead.activity.cancel`, `sales.activity.cancel`, `sales.activity.overdue.read`, `sales.lead.intake`, `sales.offer.create`, `sales.offer.revise`, `sales.offer.transition`, `sales.negotiation.record`, `sales.offer.read`, `sales.offer.list`, `sales.activity.record`, `sales.activity.complete`, `sales.activity.reschedule`, `sales.followup.schedule`, `sales.property_match.record`, `sales.team.read`, `sales.team.manage`, `sales.lead.state.manage`, `sales.deal.state.read`, `sales.deal.owner.assign`, `sales.deal.commercial.set`, `sales.pipeline.stage.manage`, `sales.deal.outcome.record`, `sales.lead.qualify`, `sales.crm.inbox.process`, `sales.crm.inbox.status`, `sales.crm.inbox.recover`, `sales.lead.create`, `sales.client_case.create`, `sales.create_task`, `sales.deal.won.observation`, `sales.inbound.request.read`, `sales.workspace.use`, `sales.director.view`, `sales.admin.view`, `sales.admin.pipeline.manage`, `sales.admin.rules.manage`, `sales.admin.agents.manage`, `sales.admin.policies.manage`, `sales.admin.teams.manage`, `sales.admin.integrations.manage`, `sales.admin.audit.view`.

## `service`

- manifest: `app/Domains/Service/module.php`;
- configuration provisioners: —;
- capabilities: `service.request`, `service.ticket`, `service.assignment`, `service.sla`, `service.escalation`, `service.resolution`, `service.api.v1`.
