<script setup>
import { nextTick, onMounted, ref, watch } from 'vue';
import { useData } from 'vitepress';
import { mermaidVersion, renderMermaid } from './mermaid-runtime.mjs';

const props = defineProps({
  source: {
    type: String,
    required: true,
  },
});

const { isDark } = useData();
const diagram = ref(null);
const error = ref('');
let revision = 0;

function decodeSource() {
  const binary = window.atob(props.source);
  const bytes = Uint8Array.from(binary, (character) => character.charCodeAt(0));
  return new TextDecoder().decode(bytes);
}

async function render() {
  if (typeof window === 'undefined') return;

  const currentRevision = ++revision;
  error.value = '';
  await nextTick();

  try {
    const source = decodeSource();
    const { svg } = await renderMermaid(source, Boolean(isDark.value));
    if (currentRevision !== revision || !diagram.value) return;
    diagram.value.innerHTML = svg;
  } catch (exception) {
    if (currentRevision !== revision) return;
    error.value = exception instanceof Error ? exception.message : String(exception);
    if (diagram.value) diagram.value.textContent = decodeSource();
  }
}

onMounted(render);
watch(() => props.source, render);
watch(isDark, render);
</script>

<template>
  <figure class="cos-mermaid-diagram" :data-mermaid-version="mermaidVersion()">
    <div ref="diagram" class="cos-mermaid-canvas" />
    <figcaption v-if="error" class="cos-mermaid-error">
      Mermaid render failed: {{ error }}
    </figcaption>
  </figure>
</template>
