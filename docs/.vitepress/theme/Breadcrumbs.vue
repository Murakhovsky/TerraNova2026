<script setup>
import { computed } from 'vue';
import { useData, withBase } from 'vitepress';

const { page, frontmatter } = useData();

const ukSectionMap = {
  'for-business': ['Для бізнесу', '/for-business/'],
  'for-integrators': ['Для впровадження', '/for-integrators/'],
  'for-developers': ['Для розробників', '/for-developers/'],
  '00-start': ['Початок', '/00-start/what-is-cos.html'],
  '01-product': ['Продукт', '/01-product/current-scope.html'],
  '02-workflows': ['Бізнес-процеси', '/02-workflows/sales-lead-to-managed-case.html'],
  '03-architecture': ['Архітектура', '/03-architecture/system-map.html'],
  '04-domains': ['Домени', '/04-domains/sales/overview.html'],
  '05-runtime': ['Середовище виконання', '/05-runtime/execution-lifecycle.html'],
  '06-ai-agents': ['ШІ та агенти', '/06-ai-agents/agent-runtime.html'],
  '07-api-integrations': ['API та інтеграції', '/07-api-integrations/integration-model.html'],
  '08-ui': ['Інтерфейси', '/08-ui/documentation-site.html'],
  '09-development': ['Розробка', '/09-development/documentation-rules.html'],
  '10-operations': ['Експлуатація', '/10-operations/documentation-build.html'],
  '11-decisions': ['Архітектурні рішення', '/11-decisions/README.html'],
  '12-reference': ['Технічний довідник', '/12-reference/README.html'],
};

const enSectionMap = {
  'for-business': ['For business', '/en/for-business/'],
  'for-integrators': ['For implementation', '/en/for-integrators/'],
  'for-developers': ['For developers', '/en/for-developers/'],
};

function humanize(value) {
  return value
    .replace(/\.md$/, '')
    .replace(/^\d+-/, '')
    .replace(/[-_]+/g, ' ')
    .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

const breadcrumbs = computed(() => {
  const relativePath = page.value?.relativePath ?? '';
  if (!relativePath || relativePath === 'index.md' || relativePath === 'en/index.md') return [];

  const parts = relativePath.split('/');
  const isEnglish = parts[0] === 'en';
  if (isEnglish) parts.shift();

  const first = parts.shift();
  const currentTitle = frontmatter.value?.title || page.value?.title || humanize(parts.at(-1) || first);
  const result = [{ label: isEnglish ? 'Documentation' : 'Документація', href: withBase(isEnglish ? '/en/' : '/') }];
  const sectionMap = isEnglish ? enSectionMap : ukSectionMap;

  if (sectionMap[first]) {
    result.push({ label: sectionMap[first][0], href: withBase(sectionMap[first][1]) });
  } else {
    result.push({ label: humanize(first), href: null });
  }

  for (const part of parts.slice(0, -1)) {
    result.push({ label: humanize(part), href: null });
  }

  result.push({ label: currentTitle, href: null, current: true });
  return result;
});
</script>

<template>
  <nav v-if="breadcrumbs.length" class="cos-breadcrumbs" aria-label="Breadcrumb">
    <template v-for="(item, index) in breadcrumbs" :key="`${item.label}-${index}`">
      <span v-if="index" class="cos-breadcrumbs__separator" aria-hidden="true">/</span>
      <a v-if="item.href && !item.current" class="cos-breadcrumbs__link" :href="item.href">{{ item.label }}</a>
      <span v-else class="cos-breadcrumbs__current" :aria-current="item.current ? 'page' : undefined">{{ item.label }}</span>
    </template>
  </nav>
</template>