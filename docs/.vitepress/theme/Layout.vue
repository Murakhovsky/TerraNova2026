<script setup>
import DefaultTheme from 'vitepress/theme';
import Breadcrumbs from './Breadcrumbs.vue';
import DocMeta from './DocMeta.vue';
import RelatedPages from './RelatedPages.vue';

const { Layout } = DefaultTheme;
</script>

<template>
  <Layout>
    <template #doc-before>
      <Breadcrumbs />
      <DocMeta />
    </template>
    <template #doc-after>
      <RelatedPages />
    </template>
  </Layout>
</template>
