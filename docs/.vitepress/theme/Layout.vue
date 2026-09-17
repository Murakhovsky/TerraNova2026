<script setup>
import { computed } from 'vue';
import { useData } from 'vitepress';
import DefaultTheme from 'vitepress/theme';
import Breadcrumbs from './Breadcrumbs.vue';
import DocMeta from './DocMeta.vue';
import RelatedPages from './RelatedPages.vue';
import LocaleSwitcher from './LocaleSwitcher.vue';

const { Layout } = DefaultTheme;
const { page } = useData();

const showTechnicalMeta = computed(() => {
  const path = page.value?.relativePath ?? '';
  return !/^for-(?:business|integrators)\//.test(path);
});
</script>

<template>
  <Layout>
    <template #nav-bar-content-after>
      <LocaleSwitcher />
    </template>
    <template #nav-screen-content-after>
      <LocaleSwitcher />
    </template>
    <template #doc-before>
      <Breadcrumbs />
      <DocMeta v-if="showTechnicalMeta" />
    </template>
    <template #doc-after>
      <RelatedPages />
    </template>
  </Layout>
</template>
