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
  view: {
    type: String,
    default: 'flow',
  },
});

const modules = import.meta.glob('../../../resources/processes/*.json', { eager: true, import: 'default' });
const definitions = Object.values(modules);
const validDirections = new Set(['TD', 'TB', 'BT', 'LR', 'RL']);
const validViews = new Set(['flow', 'ownership', 'capability']);

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

function renderEdges(process, lines) {
  for (const edge of process.edges ?? []) {
    const from = nodeId(edge.from);
    const to = nodeId(edge.to);
    if (edge.label) lines.push(`    ${from} -->|${safeLabel(edge.label)}| ${to}`);
    else lines.push(`    ${from} --> ${to}`);
  }
}

function renderFlow(process, direction) {
  const lines = [
    `flowchart ${direction}`,
    `    %% Derived from Process Registry: ${process.id}`,
  ];

  for (const step of process.steps ?? []) lines.push(`    ${renderNode(step)}`);
  renderEdges(process, lines);
  return lines.join('\n');
}

function renderOwnership(process, direction) {
  const lines = [
    `flowchart ${direction}`,
    `    %% Ownership view derived from Process Registry: ${process.id}`,
  ];

  const owners = (process.actors ?? []).filter((actor) =>
    (process.steps ?? []).some((step) => step.owner === actor),
  );

  owners.forEach((owner, index) => {
    lines.push(`    subgraph owner_${index}["${safeLabel(owner)}"]`);
    lines.push('        direction TB');
    for (const step of process.steps ?? []) {
      if (step.owner === owner) lines.push(`        ${renderNode(step)}`);
    }
    lines.push('    end');
  });

  renderEdges(process, lines);
  return lines.join('\n');
}

function capabilityKey(step) {
  if (typeof step.capability === 'string' && step.capability !== '') return `capability:${step.capability}`;
  return `gap:${step.capability_gap ?? 'missing'}`;
}

function capabilityLabel(step) {
  if (typeof step.capability === 'string' && step.capability !== '') return step.capability;
  return `GAP · ${step.capability_gap ?? 'missing capability'}`;
}

function renderCapability(process, direction) {
  const lines = [
    `flowchart ${direction}`,
    `    %% Capability view derived from Process Registry: ${process.id}`,
  ];

  const groups = [];
  const seen = new Set();
  for (const step of process.steps ?? []) {
    const key = capabilityKey(step);
    if (seen.has(key)) continue;
    seen.add(key);
    groups.push({ key, label: capabilityLabel(step) });
  }

  groups.forEach((group, index) => {
    lines.push(`    subgraph capability_${index}["${safeLabel(group.label)}"]`);
    lines.push('        direction TB');
    for (const step of process.steps ?? []) {
      if (capabilityKey(step) === group.key) lines.push(`        ${renderNode(step)}`);
    }
    lines.push('    end');
  });

  renderEdges(process, lines);
  return lines.join('\n');
}

const mermaidSource = computed(() => {
  const process = definition.value;
  if (!process) return '';

  const direction = validDirections.has(props.direction) ? props.direction : 'TD';
  const view = validViews.has(props.view) ? props.view : 'flow';
  if (view === 'ownership') return renderOwnership(process, direction);
  if (view === 'capability') return renderCapability(process, direction);
  return renderFlow(process, direction);
});
</script>

<template>
  <div class="cos-process-diagram" :data-process-id="processId" :data-process-view="view">
    <MermaidDiagram v-if="definition" :text="mermaidSource" />
    <div v-else class="custom-block danger">
      <p class="custom-block-title">Process Registry error</p>
      <p>Unknown process id: <code>{{ processId }}</code></p>
    </div>
  </div>
</template>
