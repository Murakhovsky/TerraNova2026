<script setup>
import DefaultTheme from 'vitepress/theme';
import DocMeta from './DocMeta.vue';

const { Layout } = DefaultTheme;
</script>

<template>
  <Layout>
    <template #doc-before>
      <DocMeta />
    </template>
  </Layout>
</template>
