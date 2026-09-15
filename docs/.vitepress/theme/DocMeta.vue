<script setup>
import { computed } from 'vue';
import { useData } from 'vitepress';

const { frontmatter, page } = useData();

const meta = computed(() => {
  const value = frontmatter.value ?? {};
  const relativePath = page.value?.relativePath ?? '';
  const domainFromPath = relativePath.match(/^04-domains\/([^/]+)\//)?.[1] ?? null;

  return {
    kind: value.kind || null,
    status: value.status || null,
    domain: value.domain || domainFromPath,
    scope: value.scope || null,
  };
});

const visible = computed(() => Boolean(
  meta.value.kind || meta.value.status || meta.value.domain || meta.value.scope,
));
</script>

<template>
  <div v-if="visible" class="cos-doc-meta" aria-label="Documentation metadata">
    <span v-if="meta.scope" class="cos-doc-meta-item" :class="`is-${meta.scope}`">
      <strong>SCOPE</strong>&nbsp; {{ meta.scope }}
    </span>
    <span v-if="meta.domain" class="cos-doc-meta-item">
      <strong>DOMAIN</strong>&nbsp; {{ meta.domain }}
    </span>
    <span v-if="meta.kind" class="cos-doc-meta-item">
      <strong>KIND</strong>&nbsp; {{ meta.kind }}
    </span>
    <span v-if="meta.status" class="cos-doc-meta-item" :class="`is-${meta.status}`">
      <strong>STATUS</strong>&nbsp; {{ meta.status }}
    </span>
    <span class="cos-doc-meta-item is-source">
      <strong>DOC</strong>&nbsp; main · <strong>CODE</strong>&nbsp; main
    </span>
  </div>
</template>