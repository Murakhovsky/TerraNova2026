<script setup>
import { onMounted, watch } from 'vue';
import { useLocale } from './locale-runtime.mjs';

const { locale, t, initializeLocale, localizedHref } = useLocale();

function updateDocumentTitle() {
  if (typeof document === 'undefined') return;
  document.title = t('home.title', 'COS — Company Operating System');
}

onMounted(() => {
  initializeLocale();
  updateDocumentTitle();
});
watch(locale, updateDocumentTitle);
</script>

<template>
  <div class="cos-home-shell" :data-locale="locale">
    <section class="cos-home-section cos-home-section--first">
      <div class="cos-home-section__head">
        <div>
          <div class="cos-home-kicker">{{ t('home.kicker') }}</div>
          <h2>{{ t('home.heading') }}</h2>
        </div>
        <p>{{ t('home.lead') }}</p>
      </div>

      <div class="cos-home-route-grid">
        <a class="cos-home-route cos-home-route--primary" :href="localizedHref('/for-business/')">
          <small>{{ t('home.businessLabel') }}</small>
          <strong>{{ t('home.businessTitle') }}</strong>
          <span>{{ t('home.businessText') }}</span>
        </a>
        <a class="cos-home-route" :href="localizedHref('/for-integrators/')">
          <small>{{ t('home.integratorLabel') }}</small>
          <strong>{{ t('home.integratorTitle') }}</strong>
          <span>{{ t('home.integratorText') }}</span>
        </a>
        <a class="cos-home-route" :href="localizedHref('/for-developers/')">
          <small>{{ t('home.developerLabel') }}</small>
          <strong>{{ t('home.developerTitle') }}</strong>
          <span>{{ t('home.developerText') }}</span>
        </a>
      </div>
    </section>

    <section class="cos-home-section">
      <div class="cos-home-section__head">
        <div>
          <div class="cos-home-kicker">{{ t('home.essenceKicker') }}</div>
          <h2>{{ t('home.essenceTitle') }}</h2>
        </div>
        <p>{{ t('home.essenceText') }}</p>
      </div>

      <div class="cos-home-flow">
        <div class="cos-home-flow__node"><strong>{{ t('home.flowNeed') }}</strong><span>{{ t('home.flowNeedText') }}</span></div>
        <div class="cos-home-flow__arrow">→</div>
        <div class="cos-home-flow__node"><strong>{{ t('home.flowProcess') }}</strong><span>{{ t('home.flowProcessText') }}</span></div>
        <div class="cos-home-flow__arrow">→</div>
        <div class="cos-home-flow__node"><strong>{{ t('home.flowAuthority') }}</strong><span>{{ t('home.flowAuthorityText') }}</span></div>
        <div class="cos-home-flow__arrow">→</div>
        <div class="cos-home-flow__node is-core"><strong>COS</strong><span>{{ t('home.flowCoreText') }}</span></div>
        <div class="cos-home-flow__arrow">→</div>
        <div class="cos-home-flow__node"><strong>{{ t('home.flowResult') }}</strong><span>{{ t('home.flowResultText') }}</span></div>
      </div>
    </section>

    <section class="cos-home-section cos-home-section--technical">
      <div class="cos-home-section__head">
        <div>
          <div class="cos-home-kicker">{{ t('home.statusKicker') }}</div>
          <h2>{{ t('home.statusTitle') }}</h2>
        </div>
        <p>{{ t('home.statusText') }}</p>
      </div>
      <KnowledgeHealth />
      <SystemStatus />
    </section>

    <div class="cos-home-footer-note">
      <span>{{ t('home.footer') }}</span>
      <span>COS · Terra Nova</span>
    </div>
  </div>
</template>
