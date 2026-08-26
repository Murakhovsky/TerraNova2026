<?php
declare(strict_types=1);

namespace Modules\Frontend\Controllers;

final class CompanyOsController extends ControllerBase
{
    private const LANGUAGES = ['en', 'de', 'fr', 'pl', 'uk'];

    public function indexAction(?string $lang = 'en'): void
    {
        $lang = $this->language($lang);
        $this->view->lang = $lang;
        $this->view->copy = $this->copy()[$lang];
        $this->view->domains = $this->domains();
        $this->view->languages = self::LANGUAGES;
        $this->view->cosSite = true;
        $this->view->metaTitle = 'COS — Company Operating System';
        $this->view->metaDescription = 'The governing layer that turns business events into decisions, actions and measurable results.';
    }

    public function domainAction(?string $lang = 'en', ?string $slug = null): void
    {
        $lang = $this->language($lang);
        $domains = $this->domains();

        if (!$slug || !isset($domains[$slug])) {
            $this->response->setStatusCode(404, 'Not Found');
            $this->view->pick('company_os/not_found');
            $this->view->lang = $lang;
            $this->view->cosSite = true;
            $this->view->metaTitle = 'Domain not found — COS';
            return;
        }

        $this->view->lang = $lang;
        $this->view->copy = $this->copy()[$lang];
        $this->view->domain = $domains[$slug];
        $this->view->domains = $domains;
        $this->view->languages = self::LANGUAGES;
        $this->view->cosSite = true;
        $this->view->metaTitle = $domains[$slug]['title'] . ' — COS Domain';
        $this->view->metaDescription = $domains[$slug]['summary'];
    }

    private function language(?string $lang): string
    {
        $lang = strtolower((string) $lang);
        return in_array($lang, self::LANGUAGES, true) ? $lang : 'en';
    }

    private function domains(): array
    {
        return [
            'executive-control' => ['id' => '01', 'title' => 'Executive Control', 'group' => 'Control', 'summary' => 'A live operating picture for leaders — focused on deviations, priorities and accountable outcomes.', 'outcomes' => ['Daily exception briefings', 'Plan vs actual and KPI/SLA control', 'Cash-flow and performance forecasting', 'Decision journal with result verification'], 'signals' => ['KPI drift', 'SLA breach', 'Cash-gap risk', 'Cross-team delay']],
            'sales' => ['id' => '02', 'title' => 'Sales', 'group' => 'Revenue', 'summary' => 'Turns every lead, call and deal change into the next best commercial action.', 'outcomes' => ['Lead qualification and routing', 'Follow-up and promise control', 'Pipeline and revenue forecasting', 'Upsell, renewal and reactivation'], 'signals' => ['New lead', 'Missed call', 'Stalled deal', 'Renewal window']],
            'marketing' => ['id' => '03', 'title' => 'Marketing', 'group' => 'Revenue', 'summary' => 'Connects campaigns to revenue and continuously acts on audience, spend and demand signals.', 'outcomes' => ['Revenue attribution', 'Dynamic segmentation', 'Experiment and budget control', 'Demand forecasting'], 'signals' => ['Spend anomaly', 'Intent signal', 'Segment shift', 'Experiment result']],
            'customer-service' => ['id' => '04', 'title' => 'Customer Service', 'group' => 'Experience', 'summary' => 'Coordinates every request across channels while protecting SLA, quality and retention.', 'outcomes' => ['Unified request intake', 'Classification and routing', 'Proactive delay communication', 'Churn and conflict prevention'], 'signals' => ['New request', 'Negative sentiment', 'SLA risk', 'Repeated issue']],
            'operations' => ['id' => '05', 'title' => 'Operations', 'group' => 'Execution', 'summary' => 'Converts operating rules into controlled workflows that move across people and systems.', 'outcomes' => ['Workflow orchestration', 'Dependency and hand-off control', 'Exception management', 'Cycle-time optimisation'], 'signals' => ['Order created', 'Task blocked', 'Deadline risk', 'Quality deviation']],
            'projects' => ['id' => '06', 'title' => 'Project Delivery', 'group' => 'Execution', 'summary' => 'Keeps scope, dependencies, resources, cost and decisions aligned with the target outcome.', 'outcomes' => ['Goal-to-plan decomposition', 'Critical-path control', 'Risk and resource forecasting', 'Automated status reporting'], 'signals' => ['Scope change', 'Dependency slip', 'Budget drift', 'Decision made']],
            'finance' => ['id' => '07', 'title' => 'Finance', 'group' => 'Control', 'summary' => 'Consolidates financial events and acts before receivables, margin or liquidity become problems.', 'outcomes' => ['Receivables and payables control', 'Cash-flow forecasting', 'Margin and profitability analysis', 'Budget and payment governance'], 'signals' => ['Invoice overdue', 'Payment matched', 'Cost anomaly', 'Margin decline']],
            'procurement' => ['id' => '08', 'title' => 'Procurement', 'group' => 'Supply', 'summary' => 'Coordinates demand, approvals, suppliers, offers and delivery performance end to end.', 'outcomes' => ['Purchase request automation', 'Offer comparison', 'Supplier reliability control', 'Shortage and price-risk detection'], 'signals' => ['Stock threshold', 'Price increase', 'Late supplier', 'Approval pending']],
            'logistics' => ['id' => '09', 'title' => 'Inventory & Logistics', 'group' => 'Supply', 'summary' => 'Maintains reliable inventory and delivery flows through prediction, traceability and action.', 'outcomes' => ['Demand and stock forecasting', 'Shipment and delivery control', 'Route and load optimisation', 'Returns and batch traceability'], 'signals' => ['Shortage risk', 'Excess stock', 'Shipment delay', 'Return received']],
            'manufacturing' => ['id' => '10', 'title' => 'Manufacturing & Maintenance', 'group' => 'Industry', 'summary' => 'Coordinates production, quality, equipment and maintenance around actual operating signals.', 'outcomes' => ['Production planning', 'Downtime and quality control', 'Predictive maintenance', 'Actual cost analysis'], 'signals' => ['Machine anomaly', 'Material shortage', 'Quality deviation', 'Repair request']],
            'construction' => ['id' => '11', 'title' => 'Construction & Real Estate', 'group' => 'Industry', 'summary' => 'Connects assets, buyers, contractors, documents and project delivery in one operational context.', 'outcomes' => ['Asset and listing synchronisation', 'Lead-to-deal coordination', 'Construction progress control', 'Investor and buyer updates'], 'signals' => ['Listing change', 'Viewing feedback', 'Milestone slip', 'Budget deviation']],
            'people' => ['id' => '12', 'title' => 'People & HR', 'group' => 'Organisation', 'summary' => 'Supports hiring, onboarding, knowledge and team operations while keeping people decisions human.', 'outcomes' => ['Candidate pipeline coordination', 'Personalised onboarding', 'Skills and learning control', 'Access and hand-over workflows'], 'signals' => ['Candidate enters stage', 'Employee joins', 'Skill gap', 'Role change']],
            'knowledge' => ['id' => '13', 'title' => 'Documents & Knowledge', 'group' => 'Organisation', 'summary' => 'Makes documents, obligations and institutional knowledge searchable, current and actionable.', 'outcomes' => ['Source-linked enterprise search', 'Document recognition and extraction', 'Version and completeness control', 'Template-based generation'], 'signals' => ['Document received', 'Version changed', 'Obligation due', 'Policy updated']],
            'compliance' => ['id' => '14', 'title' => 'Legal & Compliance', 'group' => 'Trust', 'summary' => 'Monitors obligations, approvals, consent and evidence without replacing accountable experts.', 'outcomes' => ['Contract obligation control', 'Licence and consent monitoring', 'Approval audit trail', 'Audit evidence preparation'], 'signals' => ['Contract changed', 'Consent expires', 'Clause anomaly', 'Incident opened']],
            'partners' => ['id' => '15', 'title' => 'Partner Networks', 'group' => 'Growth', 'summary' => 'Operates partner onboarding, lead flow, SLA, certification, commissions and channel health.', 'outcomes' => ['Partner onboarding', 'Lead and SLA control', 'Commission reconciliation', 'Partner performance and reactivation'], 'signals' => ['Partner joins', 'Lead transferred', 'SLA missed', 'Activity declines']],
            'product' => ['id' => '16', 'title' => 'Product Intelligence', 'group' => 'Growth', 'summary' => 'Connects customer evidence to prioritisation, adoption, experiments and product commitments.', 'outcomes' => ['Feedback classification', 'Value-based prioritisation', 'Activation and churn analysis', 'Launch and experiment control'], 'signals' => ['Feature request', 'Usage drop', 'Experiment ends', 'Commitment due']],
            'security' => ['id' => '17', 'title' => 'Information Security', 'group' => 'Trust', 'summary' => 'Applies least privilege, human confirmation, monitoring and recovery to every critical action.', 'outcomes' => ['Role and access control', 'Anomaly and export monitoring', 'Sensitive-data protection', 'Incident response and recovery'], 'signals' => ['Privilege change', 'Risky action', 'Data export', 'Activity anomaly']],
            'ai-governance' => ['id' => '18', 'title' => 'AI Agent Governance', 'group' => 'Trust', 'summary' => 'Controls what every agent may see, decide and do — with validation, traceability and human gates.', 'outcomes' => ['Agent registry and permissions', 'Autonomy levels and approvals', 'Quality and cost control', 'Rollback and human hand-off'], 'signals' => ['Agent invoked', 'Risk threshold', 'Validation failed', 'Cost limit']],
            'communications' => ['id' => '19', 'title' => 'Communications', 'group' => 'Organisation', 'summary' => 'Transforms conversations into structured context, commitments, tasks and timely follow-up.', 'outcomes' => ['Unified communication history', 'Intent and topic routing', 'Meeting and thread summaries', 'Commitment and response control'], 'signals' => ['Message received', 'Promise detected', 'Meeting ended', 'Reply overdue']],
            'exception-management' => ['id' => '20', 'title' => 'Exception Management', 'group' => 'Core', 'summary' => 'Lets normal work flow silently and concentrates human attention only where judgement is needed.', 'outcomes' => ['Automatic correction of simple deviations', 'Decision support for complex exceptions', 'Critical process stops and escalation', 'Rule improvement after recurrence'], 'signals' => ['Deviation detected', 'Retry exhausted', 'Critical breach', 'Pattern repeats']],
            'data-fabric' => ['id' => '21', 'title' => 'Operational Data Fabric', 'group' => 'Core', 'summary' => 'Builds a coherent, current business context across CRM, ERP, communications and internal systems.', 'outcomes' => ['Unified entity profiles', 'Duplicate and discrepancy resolution', 'Event chronology reconstruction', 'Missing and stale data detection'], 'signals' => ['Record changed', 'Duplicate found', 'Data conflict', 'Context missing']],
        ];
    }

    private function copy(): array
    {
        $en = ['nav_platform' => 'Platform', 'nav_domains' => 'Domains', 'nav_trust' => 'Trust', 'nav_start' => 'Start small', 'cta' => 'Discuss a first process', 'eyebrow' => 'Company Operating System', 'hero' => 'Your company already emits the signals. COS turns them into outcomes.', 'intro' => 'A governing layer above your CRM, ERP, communications and internal services. COS connects context, decisions, actions and measurable results — without replacing the systems that already run your business.', 'not_crm' => 'Not another CRM. Not an AI chat.', 'positioning' => 'An operational brain above the systems you already own.', 'cycle_title' => 'One continuous operating cycle', 'cycle_copy' => 'COS observes what changed, understands the business context, selects the next action, executes within policy and verifies the result.', 'event' => 'Event', 'decision' => 'Decision', 'action' => 'Action', 'result' => 'Result', 'domains_title' => 'Autonomous by domain. Coherent as a system.', 'domains_copy' => 'Deploy one domain around an expensive process. Add more when the result is proven — each module shares the same events, controls and audit trail.', 'view_domain' => 'Explore domain', 'trust_title' => 'Autonomy with enterprise control', 'trust_copy' => 'Every action is bounded by permissions, policies, human approvals and a complete decision trail.', 'start_title' => 'Start with one costly process.', 'start_copy' => 'Connect the systems involved, define the operating rule, measure the baseline and let COS close the loop. No rip-and-replace programme.', 'back' => 'All domains', 'domain_cycle' => 'How this domain operates', 'outcomes' => 'Operational outcomes', 'signals' => 'Signals COS acts on', 'integrates' => 'Works across your existing stack', 'human' => 'Human confirmation remains mandatory for high-risk actions.'];
        $overrides = [
            'de' => ['hero' => 'Ihr Unternehmen sendet längst Signale. COS verwandelt sie in Ergebnisse.', 'intro' => 'Eine Steuerungsebene über CRM, ERP, Kommunikation und internen Services. COS verbindet Kontext, Entscheidungen, Aktionen und messbare Ergebnisse — ohne bestehende Systeme zu ersetzen.', 'cta' => 'Ersten Prozess besprechen', 'domains_title' => 'Autonom je Fachbereich. Konsistent als System.', 'view_domain' => 'Domain ansehen', 'back' => 'Alle Domains', 'outcomes' => 'Operative Ergebnisse', 'signals' => 'Signale, auf die COS reagiert'],
            'fr' => ['hero' => 'Votre entreprise émet déjà les signaux. COS les transforme en résultats.', 'intro' => 'Une couche de pilotage au-dessus du CRM, de l’ERP, des communications et des services internes, sans remplacer les systèmes existants.', 'cta' => 'Discuter du premier processus', 'domains_title' => 'Autonome par domaine. Cohérent comme système.', 'view_domain' => 'Explorer le domaine', 'back' => 'Tous les domaines', 'outcomes' => 'Résultats opérationnels', 'signals' => 'Signaux traités par COS'],
            'pl' => ['hero' => 'Twoja firma już generuje sygnały. COS zamienia je w wyniki.', 'intro' => 'Warstwa sterująca nad CRM, ERP, komunikacją i usługami wewnętrznymi — bez zastępowania systemów, które już prowadzą biznes.', 'cta' => 'Omów pierwszy proces', 'domains_title' => 'Autonomiczny w domenie. Spójny jako system.', 'view_domain' => 'Poznaj domenę', 'back' => 'Wszystkie domeny', 'outcomes' => 'Rezultaty operacyjne', 'signals' => 'Sygnały obsługiwane przez COS'],
            'uk' => ['hero' => 'Ваш бізнес уже генерує сигнали. COS перетворює їх на результат.', 'intro' => 'Керівний шар над CRM, ERP, комунікаціями та внутрішніми сервісами. COS поєднує контекст, рішення, дії й вимірюваний результат — без заміни систем, які вже працюють.', 'cta' => 'Обговорити перший процес', 'domains_title' => 'Автономний у домені. Цілісний як система.', 'view_domain' => 'Переглянути домен', 'back' => 'Усі домени', 'outcomes' => 'Операційні результати', 'signals' => 'Сигнали, на які реагує COS'],
        ];
        $copy = ['en' => $en];
        foreach ($overrides as $lang => $values) { $copy[$lang] = array_merge($en, $values); }
        return $copy;
    }
}
