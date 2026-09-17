<script setup>
import { nextTick, onMounted, watch } from 'vue';
import { useRoute } from 'vitepress';
import { useLocale } from './locale-runtime.mjs';

const route = useRoute();
const { locale, locales, setLocale, initializeLocale, syncLocaleUrl, t } = useLocale();

function change(event) {
  setLocale(event.target.value);
}

onMounted(() => {
  initializeLocale();
});

watch(
  () => route.path,
  async () => {
    await nextTick();
    syncLocaleUrl();
  },
);
</script>

<template>
  <label class="cos-locale-switcher" :aria-label="t('ui.language', 'Language')">
    <span class="cos-locale-switcher__label">{{ t('ui.language', 'Language') }}</span>
    <select :value="locale" @change="change">
      <option v-for="item in locales" :key="item.code" :value="item.code">
        {{ item.native_label }}
      </option>
    </select>
  </label>
</template>
