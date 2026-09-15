<script setup>
import { computed } from 'vue';
import MermaidDiagram from './MermaidDiagram.vue';

const props = defineProps({
  processId: {
    type: String,
    required: true,
  },
  direction: {
    type: String,
    default: 'TD',
  },
});

const modules = import.meta.glob('../processes/*.json', { eager: true, import: 'default' });
const definitions = Object.values(modules);
const validDirections = new Set(['TD', 'TB', 'BT', 'LR', 'RL']);

const definition = computed(() => definitions.find((candidate) => candidate.id === props.processId) ?? null);

function nodeId(value) {
  return `step_${String(value).replace(/[^A-Za-z0-9_]/g, '_')}`;
}

function safeLabel(value) {
  return String(value ?? '')
    .replace(/\r?\n/g, ' ')
    .replace(/"/g, "'")
    .replace(/\|/g, '/');
}

function renderNode(step) {
  const id = nodeId(step.id);
  const label = safeLabel(step.label);

  if (step.kind === 'decision') return `${id}{"${label}"}`;
  if (step.kind === 'outcome') return `${id}(["${label}"])`;
  if (step.kind === 'state') return `${id}[["${label}"]]`;
  if (step.kind === 'manual') return `${id}[/"${label}"/]`;
  return `${id}["${label}"]`;
}

const mermaidSource = computed(() => {
  const process = definition.value;
  if (!process) return '';

  const direction = validDirections.has(props.direction) ? props.direction : 'TD';
  const lines = [
    `flowchart ${direction}`,
    `    %% Derived from Process Registry: ${process.id}`,
  ];

  for (const step of process.steps ?? []) {
    lines.push(`    ${renderNode(step)}`);
  }

  for (const edge of process.edges ?? []) {
    const from = nodeId(edge.from);
    const to = nodeId(edge.to);
    if (edge.label) lines.push(`    ${from} -->|${safeLabel(edge.label)}| ${to}`);
    else lines.push(`    ${from} --> ${to}`);
  }

  return lines.join('\n');
});
</script>

<template>
  <div class="cos-process-diagram" :data-process-id="processId">
    <MermaidDiagram v-if="definition" :text="mermaidSource" />
    <div v-else class="custom-block danger">
      <p class="custom-block-title">Process Registry error</p>
      <p>Unknown process id: <code>{{ processId }}</code></p>
    </div>
  </div>
</template>
