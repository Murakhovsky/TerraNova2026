export const DOCUMENTATION_CONTRACTS = Object.freeze({
  'concept-v1': Object.freeze({
    kind: 'concept',
    minH2: 2,
  }),
  'workflow-v2': Object.freeze({
    kind: 'workflow',
    minH2: 3,
    requiredSections: ['Business goal', 'Actors', 'Code map'],
    requiredFrontmatter: Object.freeze({
      process_state: Object.freeze(['as-is', 'to-be']),
      process_id: Object.freeze([]),
    }),
    requiredPatterns: Object.freeze(['<ProcessDiagram\\s+process-id=']),
  }),
  'architecture-v1': Object.freeze({
    kind: 'architecture',
    minH2: 2,
  }),
  'domain-v1': Object.freeze({
    kind: 'domain',
    minH2: 3,
  }),
  'how-to-v1': Object.freeze({
    kind: 'how-to',
    minH2: 3,
    requiredPatterns: ['^##\\s+(?:\\d+\\.\\s+)?(?:Verify|Verification)\\s*$'],
  }),
  'reference-v1': Object.freeze({
    kind: 'reference',
    minH2: 1,
  }),
});

export const DOCUMENTATION_CONTRACT_NAMES = Object.freeze(Object.keys(DOCUMENTATION_CONTRACTS));
