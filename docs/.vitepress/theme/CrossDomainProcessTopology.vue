<script setup>
import { computed } from 'vue';
import MermaidDiagram from './MermaidDiagram.vue';

const modules = import.meta.glob('../processes/*.json', { eager: true, import: 'default' });
const definitions = Object.values(modules);

function safeLabel(value) {
  return String(value ?? '')
    .replace(/\r?\n/g, ' ')
    .replace(/"/g, "'")
    .replace(/\|/g, '/');
}

function nodeId(prefix, value) {
  return `${prefix}_${String(value).replace(/[^A-Za-z0-9_]/g, '_')}`;
}

function shortContract(ref) {
  return String(ref ?? '').split('\\').filter(Boolean).at(-1) ?? String(ref ?? 'contract');
}

const mermaidSource = computed(() => {
  const hops = [];
  for (const process of definitions) {
    for (const step of process.steps ?? []) {
      const targetDomain = step.domain ?? process.domain;
      if (targetDomain === process.domain) continue;
      const contract = (step.runtime ?? []).find((mapping) => mapping?.type === 'contract')?.ref ?? 'contract';
      hops.push({ process, step, targetDomain, contract });
    }
  }

  if (hops.length === 0) {
    return ['flowchart LR', '    none["No canonical cross-domain process hops"]'].join('\n');
  }

  const lines = [
    'flowchart LR',
    '    %% Derived from Process Registry cross-domain steps',
  ];

  const processIds = new Map();
  const domains = new Set();
  let processIndex = 0;

  for (const hop of hops) {
    if (!processIds.has(hop.process.id)) {
      const id = `process_${processIndex}`;
      processIndex += 1;
      processIds.set(hop.process.id, id);
      lines.push(`    ${id}["${safeLabel(hop.process.title)}"]`);
    }
    domains.add(hop.process.domain);
    domains.add(hop.targetDomain);
  }

  for (const domain of [...domains].sort()) {
    lines.push(`    ${nodeId('domain', domain)}["${safeLabel(domain)}"]`);
  }

  hops.forEach((hop, index) => {
    const processId = processIds.get(hop.process.id);
    const fromDomain = nodeId('domain', hop.process.domain);
    const toDomain = nodeId('domain', hop.targetDomain);
    const contractId = `contract_${index}`;
    const capabilityId = `capability_${index}`;
    const capability = hop.step.capability ?? hop.step.capability_gap ?? 'capability gap';

    lines.push(`    ${processId} --> ${fromDomain}`);
    lines.push(`    ${fromDomain} -->|${safeLabel(hop.step.label)}| ${contractId}{"${safeLabel(shortContract(hop.contract))}"}`);
    lines.push(`    ${contractId} --> ${toDomain}`);
    lines.push(`    ${toDomain} --> ${capabilityId}["${safeLabel(capability)}"]`);
  });

  return lines.join('\n');
});
</script>

<template>
  <div class="cos-cross-domain-process-topology">
    <MermaidDiagram :text="mermaidSource" />
  </div>
</template>
