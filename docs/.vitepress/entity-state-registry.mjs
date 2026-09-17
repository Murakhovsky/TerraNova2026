import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
export const ENTITY_STATE_REGISTRY_FILE = path.join(here, 'entity-state-registry.json');

export function loadEntityStateRegistry() {
  return JSON.parse(fs.readFileSync(ENTITY_STATE_REGISTRY_FILE, 'utf8'));
}

export function buildEntityStateRegistry() {
  const registry = loadEntityStateRegistry();
  const domains = {};
  let stateModels = 0;
  let processTouchpoints = 0;

  for (const entity of registry.entities ?? []) {
    domains[entity.domain] ??= { entities: 0, stateModels: 0, processTouchpoints: 0 };
    domains[entity.domain].entities += 1;
    if (entity.state) {
      stateModels += 1;
      domains[entity.domain].stateModels += 1;
    }
    const touches = Array.isArray(entity.process_steps) ? entity.process_steps.length : 0;
    processTouchpoints += touches;
    domains[entity.domain].processTouchpoints += touches;
  }

  return {
    schemaVersion: registry.schema_version,
    reviewedMainRevision: registry.reviewed_main_revision,
    totalEntities: (registry.entities ?? []).length,
    stateModels,
    processTouchpoints,
    domains,
    entities: registry.entities ?? [],
    referenceLink: '/12-reference/entity-states.html',
  };
}
