<script setup>
import { computed, onMounted, watch } from 'vue';
import { useLocale } from './locale-runtime.mjs';

const props = defineProps({
  pageId: { type: String, required: true },
});

const structures = import.meta.glob('../../content/pages/*.json', { eager: true, import: 'default' });
const pages = Object.fromEntries(Object.values(structures).map((page) => [page.id, page]));
const page = computed(() => pages[props.pageId]);

const { locale, t, initializeLocale, localizedHref } = useLocale();

function title() {
  return page.value ? t(page.value.title_key, page.value.id) : props.pageId;
}

function updateDocumentTitle() {
  if (typeof document === 'undefined') return;
  document.title = `${title()} | COS`;
}

onMounted(() => {
  initializeLocale();
  updateDocumentTitle();
});

watch(locale, updateDocumentTitle);
</script>

<template>
  <article v-if="page" class="cos-localized-page" :data-page-id="page.id" :data-locale="locale">
    <template v-for="(block, index) in page.blocks" :key="`${block.type}-${block.id || block.key || index}`">
      <component
        :is="`h${block.level}`"
        v-if="block.type === 'heading'"
        :id="block.id"
        v-html="t(block.key)"
      />

      <p v-else-if="block.type === 'paragraph'" v-html="t(block.key)" />

      <ul v-else-if="block.type === 'list'">
        <li v-for="key in block.items" :key="key" v-html="t(key)" />
      </ul>

      <ol v-else-if="block.type === 'ordered-list'">
        <li v-for="key in block.items" :key="key" v-html="t(key)" />
      </ol>

      <ul v-else-if="block.type === 'link-list'" class="cos-localized-page__links">
        <li v-for="item in block.items" :key="`${item.key}:${item.href}`">
          <a :href="localizedHref(item.href)" v-html="t(item.key)" />
        </li>
      </ul>
    </template>
  </article>

  <div v-else class="warning custom-block">
    <p class="custom-block-title">Localization structure error</p>
    <p>Unknown page id: <code>{{ pageId }}</code></p>
  </div>
</template>
