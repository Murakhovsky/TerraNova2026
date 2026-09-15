<script setup>
import { computed } from 'vue';
import { useData, withBase } from 'vitepress';

const { theme } = useData();

const snapshot = computed(() => theme.value?.cosSystemStatus ?? null);
const modules = computed(() => snapshot.value?.modules ?? []);
const supportingAreas = computed(() => snapshot.value?.supportingAreas ?? []);

function href(path) {
  return path ? withBase(path) : undefined;
}
</script>

<template>
  <div v-if="snapshot" class="cos-runtime-status">
    <div class="cos-runtime-status__bar">
      <div class="cos-runtime-status__authority">
        <span class="cos-runtime-status__pulse" aria-hidden="true"></span>
        <div>
          <strong>Runtime-derived baseline</strong>
          <span>Версії та installable Domains читаються з current checkout під час build.</span>
        </div>
      </div>
      <div class="cos-runtime-status__facts" aria-label="Runtime documentation status">
        <span><small>BRANCH</small>{{ snapshot.branch }}</span>
        <span><small>DOMAINS</small>{{ snapshot.totalModules }}</span>
        <span><small>DOCS</small>{{ snapshot.documentedModules }}/{{ snapshot.totalModules }}</span>
      </div>
    </div>

    <div class="cos-runtime-status__grid">
      <a class="cos-runtime-card is-kernel" :href="href(snapshot.kernel.link)">
        <div class="cos-runtime-card__top">
          <span>CORE / KERNEL</span>
          <code>{{ snapshot.kernel.version }}</code>
        </div>
        <h3>{{ snapshot.kernel.name }}</h3>
        <p>{{ snapshot.kernel.description }}</p>
        <div class="cos-runtime-card__footer">
          <span>Executable contract</span>
          <strong>Architecture ↗</strong>
        </div>
      </a>

      <a
        v-for="module in modules"
        :key="module.id"
        class="cos-runtime-card"
        :href="href(module.link || snapshot.referenceLink)"
      >
        <div class="cos-runtime-card__top">
          <span>DOMAIN / {{ module.id.toUpperCase() }}</span>
          <code>{{ module.version }}</code>
        </div>
        <h3>{{ module.name }}</h3>
        <p>{{ module.description }}</p>
        <div class="cos-runtime-card__meta">
          <span>schema {{ module.schemaVersion }}</span>
          <span>{{ module.capabilityCount }} capabilities</span>
        </div>
        <div class="cos-runtime-card__footer">
          <span>{{ module.documented ? 'Canonical overview' : 'Reference only' }}</span>
          <strong>{{ module.documented ? 'Domain ↗' : 'Reference ↗' }}</strong>
        </div>
      </a>
    </div>

    <div class="cos-runtime-status__lower">
      <div class="cos-runtime-status__supporting">
        <small>SUPPORTING AREAS · not installable modules</small>
        <div>
          <a
            v-for="area in supportingAreas"
            :key="area.id"
            :href="href(area.link)"
          >{{ area.name }}</a>
        </div>
      </div>
      <div class="cos-runtime-status__reference">
        <small>GENERATED FROM CODE</small>
        <p>{{ snapshot.referenceKinds.join(' · ') }}</p>
        <div>
          <a :href="href(snapshot.referenceLink)">Executable reference ↗</a>
          <a :href="href(snapshot.auditLink)">Sync audit ↗</a>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.cos-runtime-status {
  display: grid;
  gap: 0.85rem;
}

.cos-runtime-status__bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1.2rem;
  padding: 0.85rem 1rem;
  border: 1px solid color-mix(in srgb, var(--vp-c-brand-1) 38%, var(--vp-c-divider));
  border-radius: 13px;
  background: color-mix(in srgb, var(--vp-c-brand-1) 6%, var(--vp-c-bg-soft));
}

.cos-runtime-status__authority {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  min-width: 0;
}

.cos-runtime-status__pulse {
  width: 0.58rem;
  height: 0.58rem;
  flex: 0 0 auto;
  border-radius: 50%;
  background: var(--vp-c-brand-1);
  box-shadow: 0 0 0 5px color-mix(in srgb, var(--vp-c-brand-1) 12%, transparent);
}

.cos-runtime-status__authority strong,
.cos-runtime-status__authority span {
  display: block;
}

.cos-runtime-status__authority strong {
  color: var(--vp-c-text-1);
  font-size: 0.82rem;
}

.cos-runtime-status__authority span {
  margin-top: 0.15rem;
  color: var(--vp-c-text-2);
  font-size: 0.72rem;
}

.cos-runtime-status__facts {
  display: flex;
  gap: 0.45rem;
  flex: 0 0 auto;
}

.cos-runtime-status__facts > span {
  min-width: 66px;
  padding: 0.5rem 0.62rem;
  border: 1px solid var(--vp-c-divider);
  border-radius: 9px;
  background: var(--vp-c-bg);
  color: var(--vp-c-text-1);
  font-family: var(--vp-font-family-mono);
  font-size: 0.76rem;
  text-align: right;
}

.cos-runtime-status__facts small {
  display: block;
  margin-bottom: 0.12rem;
  color: var(--vp-c-text-3);
  font-size: 0.52rem;
  font-weight: 760;
  letter-spacing: 0.08em;
}

.cos-runtime-status__grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.8rem;
}

.cos-runtime-card {
  display: flex;
  min-height: 230px;
  flex-direction: column;
  padding: 1.2rem;
  border: 1px solid var(--vp-c-divider);
  border-radius: 14px;
  background: color-mix(in srgb, var(--vp-c-bg-soft) 72%, transparent);
  color: var(--vp-c-text-1) !important;
  text-decoration: none !important;
  transition: border-color 0.18s ease, transform 0.18s ease, background 0.18s ease;
}

.cos-runtime-card:hover {
  border-color: color-mix(in srgb, var(--vp-c-brand-1) 55%, var(--vp-c-divider));
  background: color-mix(in srgb, var(--vp-c-brand-1) 5%, var(--vp-c-bg-soft));
  transform: translateY(-2px);
}

.cos-runtime-card.is-kernel {
  border-color: color-mix(in srgb, var(--vp-c-brand-1) 45%, var(--vp-c-divider));
}

.cos-runtime-card__top,
.cos-runtime-card__footer,
.cos-runtime-card__meta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.7rem;
}

.cos-runtime-card__top > span {
  color: var(--vp-c-brand-1);
  font-family: var(--vp-font-family-mono);
  font-size: 0.64rem;
  font-weight: 760;
  letter-spacing: 0.09em;
}

.cos-runtime-card__top code {
  padding: 0.16rem 0.45rem;
  border: 1px solid var(--vp-c-divider);
  border-radius: 999px;
  background: var(--vp-c-bg);
  color: var(--vp-c-text-2);
  font-size: 0.64rem;
}

.cos-runtime-card h3 {
  margin: 1rem 0 0.4rem !important;
  color: var(--vp-c-text-1);
  font-size: 1.42rem;
  letter-spacing: -0.035em;
}

.cos-runtime-card p {
  margin: 0;
  color: var(--vp-c-text-2);
  font-size: 0.84rem;
  line-height: 1.56;
}

.cos-runtime-card__meta {
  justify-content: flex-start;
  flex-wrap: wrap;
  margin-top: 0.85rem;
}

.cos-runtime-card__meta span {
  padding: 0.2rem 0.42rem;
  border: 1px solid var(--vp-c-divider);
  border-radius: 999px;
  color: var(--vp-c-text-3);
  font-family: var(--vp-font-family-mono);
  font-size: 0.58rem;
}

.cos-runtime-card__footer {
  margin-top: auto;
  padding-top: 1rem;
  color: var(--vp-c-text-3);
  font-size: 0.7rem;
}

.cos-runtime-card__footer strong {
  color: var(--vp-c-text-1);
  font-size: 0.72rem;
}

.cos-runtime-status__lower {
  display: grid;
  grid-template-columns: minmax(0, 0.9fr) minmax(0, 1.1fr);
  gap: 0.8rem;
}

.cos-runtime-status__supporting,
.cos-runtime-status__reference {
  padding: 1rem;
  border: 1px solid var(--vp-c-divider);
  border-radius: 12px;
  background: color-mix(in srgb, var(--vp-c-bg-soft) 60%, transparent);
}

.cos-runtime-status__lower small {
  color: var(--vp-c-text-3);
  font-family: var(--vp-font-family-mono);
  font-size: 0.58rem;
  font-weight: 760;
  letter-spacing: 0.09em;
}

.cos-runtime-status__supporting > div,
.cos-runtime-status__reference > div {
  display: flex;
  flex-wrap: wrap;
  gap: 0.55rem 0.9rem;
  margin-top: 0.65rem;
}

.cos-runtime-status__lower a {
  color: var(--vp-c-text-1) !important;
  font-size: 0.76rem;
  font-weight: 680;
  text-decoration: none !important;
}

.cos-runtime-status__lower a:hover {
  color: var(--vp-c-brand-1) !important;
}

.cos-runtime-status__reference p {
  margin: 0.5rem 0 0;
  color: var(--vp-c-text-2);
  font-size: 0.74rem;
  line-height: 1.5;
}

@media (max-width: 760px) {
  .cos-runtime-status__bar {
    align-items: flex-start;
    flex-direction: column;
  }

  .cos-runtime-status__facts {
    width: 100%;
  }

  .cos-runtime-status__facts > span {
    flex: 1 1 0;
    text-align: left;
  }

  .cos-runtime-status__grid,
  .cos-runtime-status__lower {
    grid-template-columns: 1fr;
  }
}
</style>
