const CYTOSCAPE_SRC = 'https://cdn.jsdelivr.net/npm/cytoscape@3.34.3/dist/cytoscape.min.js';
const SYSTEM_TYPES = new Set(['kernel', 'domain', 'capability', 'service', 'extension_point', 'group']);
const RUNTIME_TYPES = new Set(['domain', 'event', 'action', 'agent', 'handler', 'group']);

function loadCytoscape() {
  if (typeof window.cytoscape === 'function') return Promise.resolve(window.cytoscape);

  return new Promise((resolve, reject) => {
    const existing = document.querySelector('script[data-cos-cytoscape]');
    if (existing) {
      existing.addEventListener('load', () => resolve(window.cytoscape), { once: true });
      existing.addEventListener('error', () => reject(new Error('Cytoscape.js failed to load.')), { once: true });
      return;
    }

    const script = document.createElement('script');
    script.src = CYTOSCAPE_SRC;
    script.async = true;
    script.dataset.cosCytoscape = '3.34.3';
    script.onload = () => typeof window.cytoscape === 'function'
      ? resolve(window.cytoscape)
      : reject(new Error('Cytoscape.js did not expose a browser API.'));
    script.onerror = () => reject(new Error('Cytoscape.js failed to load.'));
    document.head.appendChild(script);
  });
}

function graphPayload() {
  const source = document.getElementById('cos-architecture-data');
  if (!source) return { elements: [], summary: {} };
  try { return JSON.parse(source.textContent || '{}'); } catch { return { elements: [], summary: {} }; }
}

function adjacency(elements) {
  const map = new Map();
  const connect = (a, b) => {
    if (!map.has(a)) map.set(a, new Set());
    map.get(a).add(b);
  };
  elements.filter((item) => item.group === 'edges').forEach((edge) => {
    connect(edge.data.source, edge.data.target);
    connect(edge.data.target, edge.data.source);
  });
  return map;
}

function withinDepth(startId, depth, neighbors) {
  if (!startId || depth === 'all') return null;
  const maxDepth = Number(depth);
  if (!Number.isFinite(maxDepth)) return null;
  const seen = new Set([startId]);
  let frontier = new Set([startId]);
  for (let level = 0; level < maxDepth; level += 1) {
    const next = new Set();
    frontier.forEach((id) => (neighbors.get(id) || []).forEach((candidate) => {
      if (!seen.has(candidate)) { seen.add(candidate); next.add(candidate); }
    }));
    frontier = next;
    if (!frontier.size) break;
  }
  return seen;
}

function safeJson(value) {
  try { return JSON.stringify(value ?? {}, null, 2); } catch { return '{}'; }
}

function initialize(root, cytoscape) {
  const payload = graphPayload();
  const elements = Array.isArray(payload.elements) ? payload.elements : [];
  const stage = root.querySelector('[data-architecture-stage]');
  const loading = root.querySelector('[data-architecture-loading]');
  const error = root.querySelector('[data-architecture-error]');
  const details = root.querySelector('[data-architecture-details]');
  const domainSelect = root.querySelector('[data-architecture-domain]');
  const depthSelect = root.querySelector('[data-architecture-depth]');
  const search = root.querySelector('[data-architecture-search]');
  const typeControls = [...root.querySelectorAll('[data-architecture-type]')];
  const modeControls = [...root.querySelectorAll('[data-architecture-mode]')];
  const neighbors = adjacency(elements);
  const collapsed = new Set();
  let mode = 'system';
  let selectedNodeId = null;

  if (!stage || !elements.length) {
    if (loading) loading.hidden = true;
    if (error) { error.hidden = false; error.textContent = 'Architecture Graph не містить елементів для відображення.'; }
    return;
  }

  const cy = cytoscape({
    container: stage,
    elements,
    minZoom: 0.15,
    maxZoom: 3,
    wheelSensitivity: 0.18,
    style: [
      { selector: 'node', style: { label: 'data(label)', 'font-size': 10, 'text-wrap': 'wrap', 'text-max-width': 120, 'text-valign': 'center', 'text-halign': 'center', width: 48, height: 48, 'background-color': '#e4e7ec', color: '#172033', 'border-width': 1, 'border-color': '#98a2b3' } },
      { selector: 'node[type = "kernel"]', style: { width: 78, height: 78, 'background-color': '#172033', color: '#ffffff', 'border-color': '#172033' } },
      { selector: 'node[type = "domain"]', style: { width: 66, height: 66, 'background-color': '#dbeafe', 'border-color': '#2563eb' } },
      { selector: 'node[type = "capability"]', style: { shape: 'round-rectangle', width: 88, height: 38, 'background-color': '#ecfdf3', 'border-color': '#12b76a' } },
      { selector: 'node[type = "event"]', style: { shape: 'diamond', 'background-color': '#fff4e5', 'border-color': '#f79009' } },
      { selector: 'node[type = "action"]', style: { shape: 'round-rectangle', 'background-color': '#f4ebff', 'border-color': '#7f56d9' } },
      { selector: 'node[type = "agent"]', style: { shape: 'hexagon', 'background-color': '#fdf2fa', 'border-color': '#c11574' } },
      { selector: 'node[type = "service"], node[type = "handler"], node[type = "extension_point"]', style: { width: 42, height: 42, 'font-size': 9 } },
      { selector: 'node.graph-group', style: { 'background-opacity': 0.05, 'border-style': 'dashed', 'text-valign': 'top', padding: 18 } },
      { selector: 'edge', style: { width: 1.2, 'line-color': '#c5cad3', 'target-arrow-color': '#98a2b3', 'target-arrow-shape': 'triangle', 'curve-style': 'bezier', label: 'data(relation)', 'font-size': 7, color: '#667085', 'text-background-color': '#ffffff', 'text-background-opacity': 0.8, 'text-background-padding': 2 } },
      { selector: ':selected', style: { 'border-width': 4, 'border-color': '#111827' } },
      { selector: '.is-search-match', style: { 'border-width': 5, 'border-color': '#2563eb' } },
    ],
    layout: { name: 'cose', animate: false, fit: true, padding: 34, nodeRepulsion: 240000, idealEdgeLength: 90 },
  });

  if (loading) loading.hidden = true;

  const selectedTypes = () => new Set(typeControls.filter((control) => control.checked).map((control) => control.value));

  const rerunLayout = () => cy.layout({ name: 'cose', animate: false, fit: true, padding: 34, nodeRepulsion: 240000, idealEdgeLength: 90 }).run();

  const visibleByMode = () => {
    if (mode === 'domain') {
      const domainId = domainSelect?.value || null;
      return withinDepth(domainId, depthSelect?.value || '2', neighbors) || new Set();
    }
    return null;
  };

  const applyFilters = ({ layout = false } = {}) => {
    const types = selectedTypes();
    const modeNodes = visibleByMode();
    const focusNodes = mode !== 'domain' && selectedNodeId
      ? withinDepth(selectedNodeId, depthSelect?.value || 'all', neighbors)
      : null;
    const visible = new Set();

    cy.nodes().forEach((node) => {
      const type = node.data('type');
      let allowed = type === 'group' || types.has(type);
      if (mode === 'system') allowed = allowed && SYSTEM_TYPES.has(type);
      if (mode === 'runtime') allowed = allowed && RUNTIME_TYPES.has(type);
      if (modeNodes) allowed = allowed && modeNodes.has(node.id());
      if (focusNodes) allowed = allowed && focusNodes.has(node.id());

      for (const collapsedId of collapsed) {
        if (node.id() !== collapsedId && (neighbors.get(collapsedId) || new Set()).has(node.id())) allowed = false;
      }

      node.style('display', allowed ? 'element' : 'none');
      if (allowed) visible.add(node.id());
    });

    cy.edges().forEach((edge) => {
      edge.style('display', visible.has(edge.data('source')) && visible.has(edge.data('target')) ? 'element' : 'none');
    });

    if (layout) rerunLayout();
  };

  const renderDetails = (node) => {
    selectedNodeId = node.id();
    const metadata = node.data('metadata') || {};
    details.innerHTML = `
      <p class="tn-kicker">Selection</p>
      <h2>${escapeHtml(node.data('label') || node.id())}</h2>
      <dl>
        <dt>Type</dt><dd>${escapeHtml(node.data('type') || '—')}</dd>
        <dt>ID</dt><dd><code>${escapeHtml(node.id())}</code></dd>
      </dl>
      <p class="tn-kicker">Metadata</p>
      <pre>${escapeHtml(safeJson(metadata))}</pre>
      <div class="tn-architecture-details__actions">
        <button type="button" data-architecture-focus>Focus</button>
        <button type="button" data-architecture-collapse>${collapsed.has(node.id()) ? 'Expand' : 'Collapse neighbors'}</button>
      </div>`;

    details.querySelector('[data-architecture-focus]')?.addEventListener('click', () => {
      applyFilters({ layout: true });
      cy.fit(cy.elements(':visible'), 50);
    });
    details.querySelector('[data-architecture-collapse]')?.addEventListener('click', () => {
      if (collapsed.has(node.id())) collapsed.delete(node.id()); else collapsed.add(node.id());
      renderDetails(node);
      applyFilters({ layout: true });
    });
  };

  cy.on('tap', 'node', (event) => renderDetails(event.target));
  cy.on('tap', (event) => {
    if (event.target === cy) {
      selectedNodeId = null;
      if (mode !== 'domain') applyFilters();
    }
  });

  modeControls.forEach((button) => button.addEventListener('click', () => {
    mode = button.dataset.architectureMode || 'system';
    selectedNodeId = null;
    modeControls.forEach((item) => item.classList.toggle('is-active', item === button));
    if (domainSelect) domainSelect.disabled = mode !== 'domain';
    applyFilters({ layout: true });
  }));

  typeControls.forEach((control) => control.addEventListener('change', () => applyFilters({ layout: true })));
  domainSelect?.addEventListener('change', () => mode === 'domain' && applyFilters({ layout: true }));
  depthSelect?.addEventListener('change', () => applyFilters({ layout: true }));

  search?.addEventListener('input', () => {
    const query = search.value.trim().toLowerCase();
    cy.nodes().removeClass('is-search-match');
    if (!query) return;
    const matches = cy.nodes().filter((node) => `${node.data('label') || ''} ${node.id()} ${node.data('type') || ''}`.toLowerCase().includes(query));
    matches.addClass('is-search-match');
    const visibleMatches = matches.filter(':visible');
    if (visibleMatches.length) cy.fit(visibleMatches, 80);
  });

  root.querySelector('[data-architecture-fit]')?.addEventListener('click', () => cy.fit(cy.elements(':visible'), 40));
  root.querySelector('[data-architecture-reset]')?.addEventListener('click', () => {
    mode = 'system';
    selectedNodeId = null;
    collapsed.clear();
    typeControls.forEach((control) => { control.checked = true; });
    modeControls.forEach((button) => button.classList.toggle('is-active', button.dataset.architectureMode === 'system'));
    if (depthSelect) depthSelect.value = '2';
    if (search) search.value = '';
    cy.nodes().removeClass('is-search-match');
    applyFilters({ layout: true });
  });

  if (domainSelect) domainSelect.disabled = true;
  applyFilters({ layout: true });
}

function escapeHtml(value) {
  return String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

async function boot() {
  const root = document.querySelector('[data-architecture-explorer]');
  if (!root) return;
  const loading = root.querySelector('[data-architecture-loading]');
  const error = root.querySelector('[data-architecture-error]');
  try {
    const cytoscape = await loadCytoscape();
    initialize(root, cytoscape);
  } catch (exception) {
    if (loading) loading.hidden = true;
    if (error) {
      error.hidden = false;
      error.textContent = exception instanceof Error ? exception.message : 'Architecture Explorer failed to initialize.';
    }
  }
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
else boot();
