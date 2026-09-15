<script setup>
import { computed } from 'vue';
import { useData, withBase } from 'vitepress';

const { page } = useData();

const item = (text, link, description) => ({ text, link, description });

const domains = {
  sales: { label: 'Sales', workflow: '/02-workflows/sales-lead-to-managed-case', lifecycle: 'lifecycle-and-automation' },
  property: { label: 'Property', workflow: '/02-workflows/property-submission-to-publication', lifecycle: 'lifecycle-and-runtime' },
  diagnostic: { label: 'Diagnostic', workflow: '/02-workflows/diagnostic-session-to-recommendation', lifecycle: 'lifecycle-and-evaluation' },
};

const workflowDomains = {
  '02-workflows/sales-lead-to-managed-case.md': 'sales',
  '02-workflows/property-submission-to-publication.md': 'property',
  '02-workflows/diagnostic-session-to-recommendation.md': 'diagnostic',
};

function domainItems(domain) {
  const meta = domains[domain];
  const root = `/04-domains/${domain}`;
  return [
    item(`${meta.label} Overview`, `${root}/overview`, 'Ownership, purpose and current runtime scope.'),
    item('Domain Model', `${root}/domain-model`, 'Canonical vocabulary, entities and invariants.'),
    item('Lifecycle', `${root}/${meta.lifecycle}`, 'State transitions and runtime execution path.'),
    item('Contracts & Code', `${root}/contracts-and-code-map`, 'Ports, adapters, boundaries and code map.'),
    item('Business Workflow', meta.workflow, 'End-to-end business flow across the system.'),
    item('System Map', '/03-architecture/system-map', 'Return to the whole-system navigation map.'),
  ];
}

function currentRoute(relativePath) {
  return `/${relativePath.replace(/\.md$/, '')}`;
}

function pageHref(link) {
  const target = /(?:\.html|\/)$/.test(link) ? link : `${link}.html`;
  return withBase(target);
}

function withoutCurrent(entries, route) {
  return entries.filter((entry) => entry.link !== route).slice(0, 4);
}

const related = computed(() => {
  const relativePath = page.value.relativePath || '';
  const route = currentRoute(relativePath);

  const domainMatch = relativePath.match(/^04-domains\/(sales|property|diagnostic)\//);
  if (domainMatch) return withoutCurrent(domainItems(domainMatch[1]), route);

  if (relativePath === '02-workflows/business-process-modeling.md') {
    return withoutCurrent([
      item('Sales Lead → Managed Case', domains.sales.workflow, 'Current Sales intake, pipeline and governed automation flow.'),
      item('Property Submission → Publication', domains.property.workflow, 'Current Property Asset, Inventory, Listing and Publication flow.'),
      item('Diagnostic Session → Recommendation', domains.diagnostic.workflow, 'Current methodology, evidence, evaluation and recommendation flow.'),
      item('Adding a Workflow', '/09-development/adding-a-workflow', 'Developer path for adding and verifying a canonical workflow.'),
    ], route);
  }

  const workflowDomain = workflowDomains[relativePath];
  if (workflowDomain) {
    const meta = domains[workflowDomain];
    const root = `/04-domains/${workflowDomain}`;
    return withoutCurrent([
      item('Business Process Modeling', '/02-workflows/business-process-modeling', 'Mermaid conventions and AS-IS / TO-BE / runtime-verified truth states.'),
      item(`${meta.label} Domain`, `${root}/overview`, 'Semantic owner of the workflow state.'),
      item('Domain Model', `${root}/domain-model`, 'Vocabulary and invariants behind the workflow.'),
      item('Domain Lifecycle', `${root}/${meta.lifecycle}`, 'State transitions and automation/runtime behavior.'),
    ], route);
  }

  if (relativePath.startsWith('01-product/')) {
    return withoutCurrent([
      item('Vision & Principles', '/01-product/vision-and-principles', 'Why COS exists and its non-negotiable product laws.'),
      item('Product Capabilities', '/01-product/capabilities', 'What the system enables across business, runtime and platform layers.'),
      item('Actors & Authority', '/01-product/actors-and-authority', 'Human, automation, Agent and integration authority model.'),
      item('System Boundaries', '/01-product/system-boundaries', 'What COS, Domains, interfaces and adapters own.'),
      item('Current Scope', '/01-product/current-scope', 'Executable AS-IS reality in the current checkout.'),
    ], route);
  }

  if (relativePath.startsWith('05-runtime/')) {
    return withoutCurrent([
      item('COS Mental Model', '/00-start/mental-model', 'Business intent to governed result.'),
      item('Kernel Overview', '/03-architecture/kernel-overview', 'Generic runtime responsibilities and boundaries.'),
      item('System Map', '/03-architecture/system-map', 'Navigate from business workflows to executable reference.'),
      item('Agent Runtime', '/06-ai-agents/agent-runtime', 'How agent-assisted decisions enter governed execution.'),
    ], route);
  }

  if (relativePath.startsWith('06-ai-agents/')) {
    return withoutCurrent([
      item('Agent Runtime', '/06-ai-agents/agent-runtime', 'Canonical agent execution path.'),
      item('Context & Tools', '/06-ai-agents/context-and-tools', 'Context assembly and governed tool boundaries.'),
      item('Memory Model', '/06-ai-agents/memory-model', 'Separate canonical facts, working context, retrieval and durable learned memory.'),
      item('Agent Evaluation', '/06-ai-agents/agent-evaluation', 'Measure decision quality, safety, reliability and business usefulness.'),
      item('LLM Governance', '/06-ai-agents/llm-governance', 'Provider routing, authority, privacy and runtime guardrails.'),
      item('Adding an Agent', '/09-development/adding-an-agent', 'Developer path for a governed agent.'),
    ], route);
  }

  if (relativePath.startsWith('07-api-integrations/')) {
    return withoutCurrent([
      item('Integration Model', '/07-api-integrations/integration-model', 'Canonical port/adapter and provider-boundary rules.'),
      item('API & Webhooks', '/07-api-integrations/api-and-webhooks', 'HTTP delivery, validation, idempotency and Domain handoff.'),
      item('Messaging Channels', '/07-api-integrations/messaging-channels', 'Telegram and future channels over shared application boundaries.'),
      item('External Reliability', '/07-api-integrations/external-reliability', 'Retries, timeouts, dead-letter semantics and correlation.'),
      item('Adding an Integration', '/09-development/adding-an-integration', 'Developer path for a provider-safe integration.'),
    ], route);
  }

  if (relativePath.startsWith('08-ui/')) {
    return withoutCurrent([
      item('Interface Surfaces', '/08-ui/interface-surfaces', 'Web, API, Telegram and CLI delivery boundaries.'),
      item('Workspace Model', '/08-ui/workspace-model', 'Read side, operational context and Domain-owned actions.'),
      item('Navigation & Permissions', '/08-ui/navigation-and-permissions', 'Module-aware navigation without confusing UX with security.'),
      item('Documentation UI', '/08-ui/documentation-site', 'The documentation surface as a product interface.'),
    ], route);
  }

  if (relativePath.startsWith('09-development/')) {
    return withoutCurrent([
      item('Reading Paths', '/00-start/reading-paths', 'Choose the shortest route for build or architecture work.'),
      item('Local Setup', '/09-development/local-setup', 'Start the runtime and documentation locally.'),
      item('Testing', '/09-development/testing', 'Architecture, smoke, integration, frontend and docs checks.'),
      item('Documentation Rules', '/09-development/documentation-rules', 'AS-IS/TARGET, page types and generated reference rules.'),
      item('System Map', '/03-architecture/system-map', 'Understand the boundary you are extending.'),
    ], route);
  }

  if (relativePath.startsWith('10-operations/')) {
    return withoutCurrent([
      item('Deployment & Health', '/10-operations/deployment-and-health', 'Commit-consistent deploy, readiness and business smoke.'),
      item('Module Readiness', '/10-operations/module-readiness', 'Check whether modules are operationally ready.'),
      item('Data & Migrations', '/10-operations/data-and-migrations', 'Schema and migration lifecycle.'),
      item('Observability & Incidents', '/10-operations/observability-and-incidents', 'Correlation, logs, metrics and incident triage.'),
      item('Backup & Recovery', '/10-operations/backup-and-recovery', 'Restore authoritative state and rebuild projections.'),
      item('Security Operations', '/10-operations/security-operations', 'Tenant isolation, secrets, authority and incident response.'),
    ], route);
  }

  if (relativePath.startsWith('00-start/')) {
    return withoutCurrent([
      item('COS Mental Model', '/00-start/mental-model', 'The execution model to keep in your head.'),
      item('Reading Paths', '/00-start/reading-paths', 'Understand, build or operate COS without reading everything.'),
      item('Product Vision', '/01-product/vision-and-principles', 'Why the system exists and what it refuses to compromise.'),
      item('System Map', '/03-architecture/system-map', 'Interactive architecture drill-down.'),
      item('Repository Map', '/00-start/repository-map', 'Where the system lives in code.'),
    ], route);
  }

  if (relativePath.startsWith('03-architecture/')) {
    return withoutCurrent([
      item('COS Mental Model', '/00-start/mental-model', 'Start from intent, ownership and execution.'),
      item('System Map', '/03-architecture/system-map', 'Whole-system drill-down.'),
      item('Domain Map', '/03-architecture/domain-map', 'Current bounded-context relationships.'),
      item('Repository Map', '/00-start/repository-map', 'Translate architecture into source layout.'),
      item('Execution Lifecycle', '/05-runtime/execution-lifecycle', 'Runtime semantics behind architectural boundaries.'),
    ], route);
  }

  return [];
});
</script>

<template>
  <div v-if="related.length" class="cos-system-map" aria-label="Related documentation">
    <div class="cos-map-layer">
      <div class="cos-map-title">Related pages</div>
      <div class="cos-map-grid">
        <a v-for="entry in related" :key="entry.link" class="cos-map-node" :href="pageHref(entry.link)">
          <strong>{{ entry.text }}</strong>
          <span>{{ entry.description }}</span>
        </a>
      </div>
    </div>
  </div>
</template>