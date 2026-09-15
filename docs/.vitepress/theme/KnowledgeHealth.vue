<script setup>
import { computed } from 'vue';
import { useData, withBase } from 'vitepress';

const { theme } = useData();

const snapshot = computed(() => theme.value?.cosSystemStatus ?? null);
const health = computed(() => snapshot.value?.knowledgeHealth ?? null);
const coverage = computed(() => snapshot.value?.processCoverage ?? null);
const domains = computed(() => (snapshot.value?.modules ?? []).filter((module) =>
  (module.health?.processes ?? 0) > 0 || (module.health?.debtItems ?? 0) > 0 || module.processCoverage?.status === 'exempt',
));

function href(path) {
  return path ? withBase(path) : undefined;
}

function ratio(value, total) {
  return `${value ?? 0}/${total ?? 0}`;
}
</script>

<template>
  <div v-if="health" class="cos-knowledge-health">
    <div class="cos-knowledge-health__head">
      <div>
        <small>KNOWLEDGE HEALTH · CURRENT CHECKOUT</small>
        <h3>Перевірювана модель COS</h3>
        <p>Process Registry, Domain coverage, capability mapping, runtime evidence і architecture debt рахуються зі structured authorities під час build.</p>
      </div>
      <div class="cos-knowledge-health__schemas">
        <span>PROCESS v{{ health.processSchemaVersions.join('/') }}</span>
        <span>DEBT v{{ health.debtSchemaVersion }}</span>
        <span v-if="coverage">COVERAGE v{{ coverage.exemptionSchemaVersion }}</span>
      </div>
    </div>

    <div class="cos-knowledge-health__metrics">
      <a :href="href(health.processReferenceLink)" class="cos-health-metric">
        <small>PROCESSES</small>
        <strong>{{ health.totalProcesses }}</strong>
        <span>{{ health.totalSteps }} canonical steps</span>
      </a>
      <a v-if="coverage" :href="href(coverage.referenceLink)" class="cos-health-metric">
        <small>DOMAIN COVERAGE</small>
        <strong>{{ ratio(coverage.coverageSatisfiedDomains, coverage.installableDomains) }}</strong>
        <span>{{ coverage.coveredDomains }} modeled · {{ coverage.exemptDomains }} exempt</span>
      </a>
      <a :href="href(health.processReferenceLink)" class="cos-health-metric">
        <small>CAPABILITY</small>
        <strong>{{ ratio(health.capabilityMappedSteps, health.totalSteps) }}</strong>
        <span>{{ health.capabilityGapSteps }} explicit gaps</span>
      </a>
      <a :href="href(health.processReferenceLink)" class="cos-health-metric">
        <small>EVIDENCE</small>
        <strong>{{ ratio(health.evidenceVerifiedSteps, health.totalSteps) }}</strong>
        <span>steps source/runtime verified</span>
      </a>
      <a :href="href(health.processReferenceLink)" class="cos-health-metric">
        <small>CRITICAL RUNTIME</small>
        <strong>{{ ratio(health.criticalRuntimeVerified, health.criticalSteps) }}</strong>
        <span>structurally runtime-backed</span>
      </a>
      <a :href="href(health.debtReferenceLink)" class="cos-health-metric" :class="{ 'has-debt': health.debtItems > 0 }">
        <small>CAPABILITY DEBT</small>
        <strong>{{ health.debtItems }}</strong>
        <span>{{ health.highDebtItems }} high · {{ health.mediumDebtItems }} medium</span>
      </a>
    </div>

    <div class="cos-knowledge-health__domains">
      <a
        v-for="module in domains"
        :key="module.id"
        :href="href(module.health?.debtItems > 0 ? health.debtReferenceLink : module.link || health.processReferenceLink)"
        class="cos-domain-health"
      >
        <div class="cos-domain-health__top">
          <small>DOMAIN / {{ module.id.toUpperCase() }}</small>
          <span v-if="module.processCoverage?.status === 'exempt'" class="cos-domain-health__clear">PROCESS EXEMPT</span>
          <span v-else-if="module.health.debtItems > 0" class="cos-domain-health__debt">DEBT {{ module.health.debtItems }}</span>
          <span v-else class="cos-domain-health__clear">NO DEBT</span>
        </div>
        <strong>{{ module.name }}</strong>
        <div class="cos-domain-health__facts">
          <span><small>PROCESS</small>{{ module.health.processes }}</span>
          <span><small>CAP</small>{{ ratio(module.health.capabilityMappedSteps, module.health.steps) }}</span>
          <span><small>EVIDENCE</small>{{ ratio(module.health.evidenceVerifiedSteps, module.health.steps) }}</span>
          <span><small>RUNTIME</small>{{ ratio(module.health.criticalRuntimeVerified, module.health.criticalSteps) }}</span>
        </div>
      </a>
    </div>

    <div class="cos-knowledge-health__foot">
      <span>Domain coverage, capability coverage та runtime verification навмисно не зведені в один декоративний score.</span>
      <div>
        <a v-if="coverage" :href="href(coverage.referenceLink)">Domain Coverage ↗</a>
        <a :href="href(health.processReferenceLink)">Process Registry ↗</a>
        <a :href="href(health.debtReferenceLink)">Debt Backlog ↗</a>
      </div>
    </div>
  </div>
</template>

<style scoped>
.cos-knowledge-health {
  display: grid;
  gap: 0.8rem;
  margin-bottom: 1rem;
}

.cos-knowledge-health__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  padding: 1rem;
  border: 1px solid color-mix(in srgb, var(--vp-c-brand-1) 40%, var(--vp-c-divider));
  border-radius: 14px;
  background: color-mix(in srgb, var(--vp-c-brand-1) 7%, var(--vp-c-bg-soft));
}

.cos-knowledge-health__head small,
.cos-health-metric small,
.cos-domain-health small {
  font-family: var(--vp-font-family-mono);
  font-size: 0.58rem;
  font-weight: 760;
  letter-spacing: 0.09em;
}

.cos-knowledge-health__head > div > small {
  color: var(--vp-c-brand-1);
}

.cos-knowledge-health__head h3 {
  margin: 0.3rem 0 0 !important;
  border: 0 !important;
  padding: 0 !important;
  font-size: 1.1rem;
}

.cos-knowledge-health__head p {
  max-width: 720px;
  margin: 0.35rem 0 0;
  color: var(--vp-c-text-2);
  font-size: 0.76rem;
  line-height: 1.5;
}

.cos-knowledge-health__schemas {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 0.35rem;
}

.cos-knowledge-health__schemas span,
.cos-domain-health__debt,
.cos-domain-health__clear {
  padding: 0.22rem 0.45rem;
  border: 1px solid var(--vp-c-divider);
  border-radius: 999px;
  background: var(--vp-c-bg);
  color: var(--vp-c-text-2);
  font-family: var(--vp-font-family-mono);
  font-size: 0.58rem;
}

.cos-knowledge-health__metrics {
  display: grid;
  grid-template-columns: repeat(6, minmax(0, 1fr));
  gap: 0.65rem;
}

.cos-health-metric,
.cos-domain-health {
  border: 1px solid var(--vp-c-divider);
  border-radius: 12px;
  background: color-mix(in srgb, var(--vp-c-bg-soft) 72%, transparent);
  color: var(--vp-c-text-1) !important;
  text-decoration: none !important;
  transition: border-color 0.18s ease, transform 0.18s ease, background 0.18s ease;
}

.cos-health-metric {
  min-width: 0;
  padding: 0.85rem;
}

.cos-health-metric:hover,
.cos-domain-health:hover {
  border-color: color-mix(in srgb, var(--vp-c-brand-1) 55%, var(--vp-c-divider));
  background: color-mix(in srgb, var(--vp-c-brand-1) 5%, var(--vp-c-bg-soft));
  transform: translateY(-2px);
}

.cos-health-metric.has-debt {
  border-color: color-mix(in srgb, var(--vp-c-warning-1) 45%, var(--vp-c-divider));
}

.cos-health-metric small {
  display: block;
  min-height: 1.8em;
  color: var(--vp-c-text-3);
}

.cos-health-metric strong {
  display: block;
  margin-top: 0.4rem;
  font-family: var(--vp-font-family-mono);
  font-size: 1.25rem;
  letter-spacing: -0.04em;
}

.cos-health-metric span {
  display: block;
  margin-top: 0.25rem;
  color: var(--vp-c-text-3);
  font-size: 0.64rem;
  line-height: 1.35;
}

.cos-knowledge-health__domains {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.65rem;
}

.cos-domain-health {
  padding: 0.85rem;
}

.cos-domain-health__top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}

.cos-domain-health__top > small {
  color: var(--vp-c-brand-1);
}

.cos-domain-health__debt {
  border-color: color-mix(in srgb, var(--vp-c-warning-1) 45%, var(--vp-c-divider));
}

.cos-domain-health__clear {
  border-color: color-mix(in srgb, var(--vp-c-brand-1) 35%, var(--vp-c-divider));
}

.cos-domain-health > strong {
  display: block;
  margin-top: 0.55rem;
  font-size: 0.94rem;
}

.cos-domain-health__facts {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 0.35rem;
  margin-top: 0.7rem;
}

.cos-domain-health__facts span {
  min-width: 0;
  padding: 0.42rem;
  border: 1px solid var(--vp-c-divider);
  border-radius: 8px;
  background: var(--vp-c-bg);
  color: var(--vp-c-text-1);
  font-family: var(--vp-font-family-mono);
  font-size: 0.68rem;
}

.cos-domain-health__facts small {
  display: block;
  margin-bottom: 0.12rem;
  color: var(--vp-c-text-3);
  font-size: 0.48rem;
}

.cos-knowledge-health__foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.8rem;
  padding: 0.7rem 0.85rem;
  border: 1px solid var(--vp-c-divider);
  border-radius: 10px;
  color: var(--vp-c-text-3);
  font-size: 0.68rem;
}

.cos-knowledge-health__foot > div {
  display: flex;
  flex-wrap: wrap;
  gap: 0.8rem;
  flex: 0 0 auto;
}

.cos-knowledge-health__foot a {
  color: var(--vp-c-text-1) !important;
  font-weight: 680;
  text-decoration: none !important;
}

.cos-knowledge-health__foot a:hover {
  color: var(--vp-c-brand-1) !important;
}

@media (max-width: 1100px) {
  .cos-knowledge-health__metrics {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}

@media (max-width: 760px) {
  .cos-knowledge-health__head,
  .cos-knowledge-health__foot {
    flex-direction: column;
  }

  .cos-knowledge-health__schemas {
    justify-content: flex-start;
  }

  .cos-knowledge-health__metrics {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .cos-knowledge-health__domains {
    grid-template-columns: 1fr;
  }

  .cos-domain-health__facts {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .cos-knowledge-health__foot {
    align-items: flex-start;
  }
}

@media (max-width: 480px) {
  .cos-knowledge-health__metrics {
    grid-template-columns: 1fr;
  }
}
</style>
