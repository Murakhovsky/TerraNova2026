<script setup>
import { nextTick, onMounted, ref, watch } from 'vue';
import { useData } from 'vitepress';
import { mermaidVersion, renderMermaid } from './mermaid-runtime.mjs';

const props = defineProps({
  source: {
    type: String,
    default: '',
  },
  text: {
    type: String,
    default: '',
  },
});

const { isDark } = useData();
const diagram = ref(null);
const error = ref('');
let revision = 0;

function decodeSource() {
  if (props.text) return props.text;
  if (!props.source || typeof window === 'undefined') return '';

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
    if (!source) throw new Error('Mermaid source is empty.');
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
watch(() => [props.source, props.text], render);
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
