const CYTOSCAPE_SRC = 'https://cdn.jsdelivr.net/npm/cytoscape@3.34.3/dist/cytoscape.min.js';

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
  if (!source) return { views: {}, summary: {}, default_view: '' };
  try { return JSON.parse(source.textContent || '{}'); } catch { return { views: {}, summary: {}, default_view: '' }; }
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

function escapeHtml(value) {
  return String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

function layoutOptions(hint) {
  if (hint === 'hierarchical') {
    return { name: 'breadthfirst', animate: false, fit: true, padding: 38, directed: true, spacingFactor: 1.18, avoidOverlap: true };
  }
  if (hint === 'flow') {
    return { name: 'breadthfirst', animate: false, fit: true, padding: 38, directed: true, spacingFactor: 1.12, avoidOverlap: true, circle: false };
  }
  if (hint === 'radial') {
    return {
      name: 'concentric', animate: false, fit: true, padding: 38, minNodeSpacing: 24,
      concentric: (node) => ({ kernel: 10, domain: 9, rule: 7, policy: 7, agent: 7, event: 5, action: 5, capability: 4, service: 3, handler: 2, extension_point: 2 }[node.data('type')] || 1),
      levelWidth: () => 2,
    };
  }
  return { name: 'cose', animate: false, fit: true, padding: 34, nodeRepulsion: 240000, idealEdgeLength: 90 };
}

function initialize(root, cytoscape) {
  const payload = graphPayload();
  const views = payload.views && typeof payload.views === 'object' ? payload.views : {};
  const defaultView = typeof payload.default_view === 'string' && payload.default_view ? payload.default_view : Object.keys(views)[0];
  const endpoint = root.dataset.architectureEndpoint || '';
  const domainCache = new Map();
  let mode = defaultView || '';
  let currentGraph = views[mode] || {};
  let elements = Array.isArray(currentGraph.elements) ? currentGraph.elements : [];
  let neighbors = adjacency(elements);
  let selectedNodeId = null;
  let localFocusNodeId = null;
  const collapsed = new Set();

  const stage = root.querySelector('[data-architecture-stage]');
  const loading = root.querySelector('[data-architecture-loading]');
  const error = root.querySelector('[data-architecture-error]');
  const details = root.querySelector('[data-architecture-details]');
  const typeContainer = root.querySelector('[data-architecture-types]');
  const domainSelect = root.querySelector('[data-architecture-domain]');
  const depthSelect = root.querySelector('[data-architecture-depth]');
  const search = root.querySelector('[data-architecture-search]');
  const modeControls = [...root.querySelectorAll('[data-architecture-mode]')];
  const nodeCount = root.querySelector('[data-architecture-node-count]');
  const edgeCount = root.querySelector('[data-architecture-edge-count]');
  const viewLabel = root.querySelector('[data-architecture-view-label]');

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
      { selector: 'node[type = "rule"]', style: { shape: 'round-rectangle', width: 86, height: 40, 'background-color': '#fff7ed', 'border-color': '#ea580c' } },
      { selector: 'node[type = "action"]', style: { shape: 'round-rectangle', 'background-color': '#f4ebff', 'border-color': '#7f56d9' } },
      { selector: 'node[type = "policy"]', style: { shape: 'octagon', width: 58, height: 58, 'background-color': '#fef2f2', 'border-color': '#dc2626' } },
      { selector: 'node[type = "agent"]', style: { shape: 'hexagon', 'background-color': '#fdf2fa', 'border-color': '#c11574' } },
      { selector: 'node[type = "service"], node[type = "handler"], node[type = "extension_point"]', style: { width: 42, height: 42, 'font-size': 9 } },
      { selector: 'node.graph-group', style: { 'background-opacity': 0.05, 'border-style': 'dashed', 'text-valign': 'top', padding: 18 } },
      { selector: 'edge', style: { width: 1.2, 'line-color': '#c5cad3', 'target-arrow-color': '#98a2b3', 'target-arrow-shape': 'triangle', 'curve-style': 'bezier', label: 'data(relation)', 'font-size': 7, color: '#667085', 'text-background-color': '#ffffff', 'text-background-opacity': 0.8, 'text-background-padding': 2 } },
      { selector: 'edge[relation = "triggers"]', style: { 'line-style': 'dashed', 'line-color': '#f79009', 'target-arrow-color': '#f79009' } },
      { selector: 'edge[relation = "produces"]', style: { 'line-color': '#7f56d9', 'target-arrow-color': '#7f56d9' } },
      { selector: 'edge[relation = "governs"]', style: { 'line-style': 'dotted', 'line-color': '#dc2626', 'target-arrow-color': '#dc2626' } },
      { selector: 'edge[relation = "depends_on"]', style: { width: 1.8, 'line-color': '#475467', 'target-arrow-color': '#475467' } },
      { selector: ':selected', style: { 'border-width': 4, 'border-color': '#111827' } },
      { selector: '.is-search-match', style: { 'border-width': 5, 'border-color': '#2563eb' } },
    ],
    layout: layoutOptions(currentGraph?.view?.layout || 'force'),
  });

  if (loading) loading.hidden = true;

  const typeControls = () => [...root.querySelectorAll('[data-architecture-type]')];
  const selectedTypes = () => new Set(typeControls().filter((control) => control.checked).map((control) => control.value));
  const currentLayout = () => layoutOptions(currentGraph?.view?.layout || 'force');
  const rerunLayout = () => cy.layout(currentLayout()).run();

  const showError = (message = '') => {
    if (!error) return;
    error.hidden = message === '';
    error.textContent = message;
  };

  const setLoading = (active) => {
    if (loading) loading.hidden = !active;
  };

  const updateSummary = () => {
    const summary = currentGraph?.summary || {};
    if (nodeCount) nodeCount.textContent = String(summary.nodes || 0);
    if (edgeCount) edgeCount.textContent = String(summary.edges || 0);
    if (viewLabel) viewLabel.textContent = currentGraph?.view?.label || mode || 'Graph';
  };

  const renderTypeFilters = () => {
    if (!typeContainer) return;
    const nodeTypes = currentGraph?.summary?.node_types || {};
    typeContainer.innerHTML = '';
    Object.entries(nodeTypes).forEach(([type, count]) => {
      const label = document.createElement('label');
      const input = document.createElement('input');
      const name = document.createElement('span');
      const total = document.createElement('strong');
      input.type = 'checkbox';
      input.value = type;
      input.checked = true;
      input.dataset.architectureType = '';
      name.textContent = type;
      total.textContent = String(count);
      label.append(input, name, total);
      typeContainer.append(label);
      input.addEventListener('change', () => applyFilters({ layout: true }));
    });
  };

  const applyFilters = ({ layout = false } = {}) => {
    const types = selectedTypes();
    const focusNodes = localFocusNodeId
      ? withinDepth(localFocusNodeId, depthSelect?.value || 'all', neighbors)
      : null;
    const visible = new Set();

    cy.nodes().forEach((node) => {
      const type = node.data('type');
      let allowed = type === 'group' || types.has(type);
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

  const renderEmptyDetails = () => {
    if (!details) return;
    details.innerHTML = '<p class="cos-card__eyebrow">Selection</p><h2>Node details</h2><p class="cos-card__copy">Виберіть вузол графа. Тут з’являться ownership, dependencies, runtime relations, source metadata та локальні дії.</p>';
  };

  const nodeLookup = () => {
    const map = new Map();
    elements.filter((item) => item.group === 'nodes').forEach((item) => map.set(item.data.id, item.data));
    return map;
  };

  const connectionDetails = (nodeId) => {
    const nodes = nodeLookup();
    return elements
      .filter((item) => item.group === 'edges' && (item.data.source === nodeId || item.data.target === nodeId))
      .map((edge) => {
        const outgoing = edge.data.source === nodeId;
        const neighborId = outgoing ? edge.data.target : edge.data.source;
        const neighbor = nodes.get(neighborId) || { id: neighborId, label: neighborId, type: 'unknown' };
        return {
          outgoing,
          relation: edge.data.relation || 'related',
          neighbor,
          metadata: edge.data.metadata || {},
        };
      });
  };

  const domainBreakdown = (nodeId) => {
    const nodes = nodeLookup();
    const counts = {};
    elements.filter((item) => item.group === 'edges' && item.data.source === nodeId && ['owns', 'contributes'].includes(item.data.relation)).forEach((edge) => {
      const target = nodes.get(edge.data.target);
      if (!target) return;
      counts[target.type] = (counts[target.type] || 0) + 1;
    });
    return counts;
  };

  const renderDetails = (node) => {
    if (!details) return;
    selectedNodeId = node.id();
    const metadata = node.data('metadata') || {};
    const connections = connectionDetails(node.id());
    const breakdown = node.data('type') === 'domain' ? domainBreakdown(node.id()) : {};
    const source = metadata.source_path || metadata.class || metadata.reference_source || '—';
    const version = metadata.version || metadata.schema_version || '—';
    const relationshipHtml = connections.length
      ? `<ul class="cos-architecture-relations">${connections.slice(0, 24).map((connection) => {
          const arrow = connection.outgoing ? '→' : '←';
          const sourceHint = connection.metadata?.source ? ` <small>${escapeHtml(connection.metadata.source)}</small>` : '';
          return `<li><code>${arrow} ${escapeHtml(connection.relation)}</code><span>${escapeHtml(connection.neighbor.label || connection.neighbor.id)} <small>${escapeHtml(connection.neighbor.type || '')}</small>${sourceHint}</span></li>`;
        }).join('')}</ul>`
      : '<p class="cos-card__copy">No relations in this projection.</p>';
    const breakdownHtml = Object.keys(breakdown).length
      ? `<div class="cos-architecture-breakdown">${Object.entries(breakdown).map(([type, count]) => `<span><strong>${count}</strong> ${escapeHtml(type)}</span>`).join('')}</div>`
      : '';

    details.innerHTML = `
      <p class="cos-card__eyebrow">Selection</p>
      <h2>${escapeHtml(node.data('label') || node.id())}</h2>
      <dl>
        <dt>Projection</dt><dd>${escapeHtml(currentGraph?.view?.label || mode)}</dd>
        <dt>Layout</dt><dd>${escapeHtml(currentGraph?.view?.layout || 'auto')}</dd>
        <dt>Type</dt><dd>${escapeHtml(node.data('type') || '—')}</dd>
        <dt>ID</dt><dd><code>${escapeHtml(node.id())}</code></dd>
        <dt>Version</dt><dd>${escapeHtml(version)}</dd>
        <dt>Source</dt><dd><code>${escapeHtml(source)}</code></dd>
      </dl>
      ${breakdownHtml}
      <p class="cos-card__eyebrow">Relations</p>
      ${relationshipHtml}
      <details class="cos-architecture-metadata">
        <summary>Raw metadata</summary>
        <pre>${escapeHtml(safeJson(metadata))}</pre>
      </details>
      <div class="cos-architecture-details__actions">
        <button type="button" data-architecture-focus>Focus</button>
        <button type="button" data-architecture-collapse>${collapsed.has(node.id()) ? 'Expand' : 'Collapse neighbors'}</button>
      </div>`;

    details.querySelector('[data-architecture-focus]')?.addEventListener('click', () => {
      localFocusNodeId = node.id();
      applyFilters({ layout: true });
      cy.fit(cy.elements(':visible'), 50);
    });
    details.querySelector('[data-architecture-collapse]')?.addEventListener('click', () => {
      if (collapsed.has(node.id())) collapsed.delete(node.id()); else collapsed.add(node.id());
      renderDetails(node);
      applyFilters({ layout: true });
    });
  };

  const replaceGraph = (graph, nextMode) => {
    mode = nextMode;
    currentGraph = graph || {};
    elements = Array.isArray(currentGraph.elements) ? currentGraph.elements : [];
    neighbors = adjacency(elements);
    selectedNodeId = null;
    localFocusNodeId = null;
    collapsed.clear();
    cy.elements().remove();
    cy.add(elements);
    modeControls.forEach((button) => button.classList.toggle('is-active', button.dataset.architectureMode === mode));
    if (domainSelect) domainSelect.disabled = mode !== 'domain';
    if (search) search.value = '';
    updateSummary();
    renderTypeFilters();
    renderEmptyDetails();
    applyFilters({ layout: true });
  };

  const fetchDomainProjection = async () => {
    const focus = domainSelect?.value || '';
    const depth = depthSelect?.value || '2';
    if (depth === 'all') return views.domain || {};
    if (!endpoint || !focus) return views.domain || {};

    const cacheKey = `${focus}:${depth}`;
    if (domainCache.has(cacheKey)) return domainCache.get(cacheKey);

    const url = new URL(endpoint, window.location.href);
    url.searchParams.set('view', 'domain');
    url.searchParams.set('focus', focus);
    url.searchParams.set('depth', depth);
    setLoading(true);
    showError('');
    try {
      const response = await fetch(url.toString(), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      const result = await response.json();
      if (!response.ok || !result?.ok || !result?.graph) throw new Error(result?.error || 'Domain projection request failed.');
      domainCache.set(cacheKey, result.graph);
      return result.graph;
    } finally {
      setLoading(false);
    }
  };

  const switchProjection = async (nextMode) => {
    try {
      const graph = nextMode === 'domain' ? await fetchDomainProjection() : views[nextMode];
      if (!graph || !Array.isArray(graph.elements) || !graph.elements.length) {
        showError('Architecture Graph не містить елементів для цього view.');
        return;
      }
      showError('');
      replaceGraph(graph, nextMode);
    } catch (exception) {
      showError(exception instanceof Error ? exception.message : 'Architecture projection failed to load.');
    }
  };

  cy.on('tap', 'node', (event) => renderDetails(event.target));
  cy.on('tap', (event) => {
    if (event.target === cy) {
      selectedNodeId = null;
      localFocusNodeId = null;
      applyFilters();
      renderEmptyDetails();
    }
  });

  modeControls.forEach((button) => button.addEventListener('click', () => switchProjection(button.dataset.architectureMode || defaultView)));
  domainSelect?.addEventListener('change', () => mode === 'domain' && switchProjection('domain'));
  depthSelect?.addEventListener('change', () => {
    if (mode === 'domain') switchProjection('domain');
    else if (localFocusNodeId) applyFilters({ layout: true });
  });

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
  root.querySelector('[data-architecture-reset]')?.addEventListener('click', async () => {
    if (depthSelect) depthSelect.value = '2';
    await switchProjection(defaultView);
  });

  if (domainSelect) domainSelect.disabled = mode !== 'domain';
  modeControls.forEach((button) => button.classList.toggle('is-active', button.dataset.architectureMode === mode));
  updateSummary();
  renderTypeFilters();
  applyFilters({ layout: true });
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
