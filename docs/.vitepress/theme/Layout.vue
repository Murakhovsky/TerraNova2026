<script setup>
import DefaultTheme from 'vitepress/theme';
import Breadcrumbs from './Breadcrumbs.vue';
import DocMeta from './DocMeta.vue';

const { Layout } = DefaultTheme;
</script>

<template>
  <Layout>
    <template #doc-before>
      <Breadcrumbs />
      <DocMeta />
    </template>
  </Layout>
</template>