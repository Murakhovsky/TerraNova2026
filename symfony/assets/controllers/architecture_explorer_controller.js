import { Controller } from '@hotwired/stimulus';

const CYTOSCAPE_SRC = 'https://cdn.jsdelivr.net/npm/cytoscape@3.34.3/dist/cytoscape.min.js';

export default class extends Controller {
    static targets = [
        'stage',
        'loading',
        'error',
        'details',
        'types',
        'domain',
        'depth',
        'search',
        'nodeCount',
        'edgeCount',
        'viewLabel',
    ];

    static values = {
        endpoint: String,
        defaultView: String,
    };

    async connect() {
        this.cy = null;
        this.graph = {};
        this.mode = this.defaultViewValue || 'system';
        this.collapsed = new Set();
        this.localFocusNodeId = null;

        try {
            this.cytoscape = await this.loadCytoscape();
            await this.loadProjection(this.mode);
        } catch (error) {
            this.showError(error instanceof Error ? error.message : 'Architecture Explorer failed to initialize.');
            this.setLoading(false);
        }
    }

    async switchMode(event) {
        const mode = event.currentTarget.dataset.architectureMode || this.defaultViewValue || 'system';
        await this.loadProjection(mode);
    }

    async domainChanged() {
        if (this.mode === 'domain') await this.loadProjection('domain');
    }

    async depthChanged() {
        if (this.mode === 'domain') {
            await this.loadProjection('domain');
            return;
        }
        if (this.localFocusNodeId) this.applyFilters(true);
    }

    search() {
        if (!this.cy || !this.hasSearchTarget) return;
        const query = this.searchTarget.value.trim().toLowerCase();
        this.cy.nodes().removeClass('is-search-match');
        if (!query) return;

        const matches = this.cy.nodes().filter((node) =>
            `${node.data('label') || ''} ${node.id()} ${node.data('type') || ''}`.toLowerCase().includes(query),
        );
        matches.addClass('is-search-match');
        const visible = matches.filter(':visible');
        if (visible.length) this.cy.fit(visible, 80);
    }

    fit() {
        if (this.cy) this.cy.fit(this.cy.elements(':visible'), 40);
    }

    async reset() {
        if (this.hasDepthTarget) this.depthTarget.value = '2';
        this.localFocusNodeId = null;
        this.collapsed.clear();
        await this.loadProjection(this.defaultViewValue || 'system');
    }

    async loadProjection(mode) {
        this.setLoading(true);
        this.showError('');

        try {
            const url = new URL(this.endpointValue, window.location.href);
            url.searchParams.set('view', mode);

            if (mode === 'domain' && this.hasDomainTarget && this.domainTarget.value) {
                url.searchParams.set('focus', this.domainTarget.value);
                url.searchParams.set('depth', this.hasDepthTarget ? this.depthTarget.value : '2');
            } else {
                url.searchParams.set('depth', 'all');
            }

            const response = await fetch(url.toString(), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            const payload = await response.json().catch(() => ({}));

            if (!response.ok || !payload.ok || !payload.graph) {
                throw new Error(payload.error || 'Architecture projection failed to load.');
            }

            this.replaceGraph(payload.graph, mode);
        } finally {
            this.setLoading(false);
        }
    }

    replaceGraph(graph, mode) {
        this.graph = graph || {};
        this.mode = mode;
        this.elements = Array.isArray(this.graph.elements) ? this.graph.elements : [];
        this.neighbors = this.adjacency(this.elements);
        this.localFocusNodeId = null;
        this.collapsed.clear();

        if (!this.elements.length) {
            this.showError('Architecture Graph не містить елементів для цього view.');
            return;
        }

        if (!this.cy) {
            this.cy = this.cytoscape({
                container: this.stageTarget,
                elements: this.elements,
                minZoom: 0.15,
                maxZoom: 3,
                wheelSensitivity: 0.18,
                style: this.graphStyle(),
                layout: this.layoutOptions(this.graph?.view?.layout || 'force'),
            });
            this.cy.on('tap', 'node', (event) => this.renderDetails(event.target));
            this.cy.on('tap', (event) => {
                if (event.target === this.cy) {
                    this.localFocusNodeId = null;
                    this.renderEmptyDetails();
                    this.applyFilters(false);
                }
            });
        } else {
            this.cy.elements().remove();
            this.cy.add(this.elements);
            this.cy.style(this.graphStyle());
        }

        this.element.querySelectorAll('[data-architecture-mode]').forEach((button) => {
            const active = button.dataset.architectureMode === mode;
            button.classList.toggle('cos-button--primary', active);
            button.classList.toggle('cos-button--ghost', !active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });

        if (this.hasDomainTarget) this.domainTarget.disabled = mode !== 'domain';
        if (this.hasSearchTarget) this.searchTarget.value = '';

        this.renderTypeFilters();
        this.renderEmptyDetails();
        this.updateSummary();
        this.applyFilters(true);
    }

    graphStyle() {
        const token = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();

        return [
            { selector: 'node', style: { label: 'data(label)', 'font-size': 10, 'text-wrap': 'wrap', 'text-max-width': 120, 'text-valign': 'center', 'text-halign': 'center', width: 48, height: 48, 'background-color': token('--cos-color-surface-subtle'), color: token('--cos-color-text'), 'border-width': 1, 'border-color': token('--cos-color-border-strong') } },
            { selector: 'node[type = "kernel"]', style: { width: 78, height: 78, 'background-color': token('--cos-color-shell'), color: token('--cos-color-shell-text'), 'border-color': token('--cos-color-shell') } },
            { selector: 'node[type = "domain"]', style: { width: 66, height: 66, 'background-color': token('--cos-color-primary-soft'), 'border-color': token('--cos-color-primary') } },
            { selector: 'node[type = "capability"]', style: { shape: 'round-rectangle', width: 88, height: 38, 'background-color': token('--cos-color-positive-soft'), 'border-color': token('--cos-color-positive') } },
            { selector: 'node[type = "event"], node[type = "rule"]', style: { 'background-color': token('--cos-color-warning-soft'), 'border-color': token('--cos-color-warning') } },
            { selector: 'node[type = "action"], node[type = "agent"]', style: { 'background-color': token('--cos-color-info-soft'), 'border-color': token('--cos-color-info') } },
            { selector: 'node[type = "policy"]', style: { shape: 'octagon', 'background-color': token('--cos-color-danger-soft'), 'border-color': token('--cos-color-danger') } },
            { selector: 'node[type = "service"], node[type = "handler"], node[type = "extension_point"]', style: { width: 42, height: 42, 'font-size': 9 } },
            { selector: 'edge', style: { width: 1.2, 'line-color': token('--cos-color-border-strong'), 'target-arrow-color': token('--cos-color-border-strong'), 'target-arrow-shape': 'triangle', 'curve-style': 'bezier', label: 'data(relation)', 'font-size': 7, color: token('--cos-color-text-muted'), 'text-background-color': token('--cos-color-surface'), 'text-background-opacity': 0.86, 'text-background-padding': 2 } },
            { selector: ':selected', style: { 'border-width': 4, 'border-color': token('--cos-color-text') } },
            { selector: '.is-search-match', style: { 'border-width': 5, 'border-color': token('--cos-color-primary') } },
        ];
    }

    layoutOptions(hint) {
        if (hint === 'hierarchical') return { name: 'breadthfirst', animate: false, fit: true, padding: 38, directed: true, spacingFactor: 1.18, avoidOverlap: true };
        if (hint === 'flow') return { name: 'breadthfirst', animate: false, fit: true, padding: 38, directed: true, spacingFactor: 1.12, avoidOverlap: true, circle: false };
        if (hint === 'radial') return { name: 'concentric', animate: false, fit: true, padding: 38, minNodeSpacing: 24, concentric: (node) => ({ kernel: 10, domain: 9, rule: 7, policy: 7, agent: 7, event: 5, action: 5, capability: 4 }[node.data('type')] || 1), levelWidth: () => 2 };
        return { name: 'cose', animate: false, fit: true, padding: 34, nodeRepulsion: 240000, idealEdgeLength: 90 };
    }

    renderTypeFilters() {
        if (!this.hasTypesTarget) return;
        this.typesTarget.replaceChildren();

        Object.entries(this.graph?.summary?.node_types || {}).forEach(([type, count]) => {
            const label = document.createElement('label');
            label.className = 'cos-architecture__type';

            const input = document.createElement('input');
            input.type = 'checkbox';
            input.value = type;
            input.checked = true;
            input.addEventListener('change', () => this.applyFilters(true));

            const name = document.createElement('span');
            name.textContent = type;

            const total = document.createElement('strong');
            total.textContent = String(count);

            label.append(input, name, total);
            this.typesTarget.append(label);
        });
    }

    applyFilters(runLayout = false) {
        if (!this.cy) return;

        const types = new Set(
            [...this.typesTarget.querySelectorAll('input[type="checkbox"]')]
                .filter((input) => input.checked)
                .map((input) => input.value),
        );
        const focused = this.localFocusNodeId
            ? this.withinDepth(this.localFocusNodeId, this.hasDepthTarget ? this.depthTarget.value : 'all')
            : null;
        const visible = new Set();

        this.cy.nodes().forEach((node) => {
            let allowed = node.data('type') === 'group' || types.has(node.data('type'));
            if (focused) allowed = allowed && focused.has(node.id());

            for (const collapsedId of this.collapsed) {
                if (node.id() !== collapsedId && (this.neighbors.get(collapsedId) || new Set()).has(node.id())) {
                    allowed = false;
                }
            }

            node.style('display', allowed ? 'element' : 'none');
            if (allowed) visible.add(node.id());
        });

        this.cy.edges().forEach((edge) => {
            edge.style('display', visible.has(edge.data('source')) && visible.has(edge.data('target')) ? 'element' : 'none');
        });

        if (runLayout) this.cy.layout(this.layoutOptions(this.graph?.view?.layout || 'force')).run();
    }

    withinDepth(startId, depth) {
        if (!startId || depth === 'all') return null;
        const maxDepth = Number(depth);
        if (!Number.isFinite(maxDepth)) return null;

        const seen = new Set([startId]);
        let frontier = new Set([startId]);
        for (let level = 0; level < maxDepth; level += 1) {
            const next = new Set();
            frontier.forEach((id) => (this.neighbors.get(id) || []).forEach((candidate) => {
                if (!seen.has(candidate)) {
                    seen.add(candidate);
                    next.add(candidate);
                }
            }));
            frontier = next;
            if (!frontier.size) break;
        }

        return seen;
    }

    adjacency(elements) {
        const map = new Map();
        const connect = (left, right) => {
            if (!map.has(left)) map.set(left, new Set());
            map.get(left).add(right);
        };

        elements.filter((item) => item.group === 'edges').forEach((edge) => {
            connect(edge.data.source, edge.data.target);
            connect(edge.data.target, edge.data.source);
        });

        return map;
    }

    updateSummary() {
        const summary = this.graph?.summary || {};
        if (this.hasNodeCountTarget) this.nodeCountTarget.textContent = String(summary.nodes || 0);
        if (this.hasEdgeCountTarget) this.edgeCountTarget.textContent = String(summary.edges || 0);
        if (this.hasViewLabelTarget) this.viewLabelTarget.textContent = this.graph?.view?.label || this.mode;
    }

    renderEmptyDetails() {
        if (!this.hasDetailsTarget) return;
        this.detailsTarget.replaceChildren(
            this.node('p', 'cos-card__eyebrow', 'Selection'),
            this.node('h2', 'cos-card__title', 'Node details'),
            this.node('p', 'cos-card__copy', 'Виберіть вузол графа, щоб побачити ownership, relations та source metadata.'),
        );
    }

    renderDetails(node) {
        if (!this.hasDetailsTarget) return;

        const metadata = node.data('metadata') || {};
        const connections = this.connections(node.id());
        const title = this.node('h2', 'cos-card__title', node.data('label') || node.id());
        const list = document.createElement('dl');

        [
            ['Projection', this.graph?.view?.label || this.mode],
            ['Layout', this.graph?.view?.layout || 'auto'],
            ['Type', node.data('type') || '—'],
            ['ID', node.id()],
            ['Version', metadata.version || metadata.schema_version || '—'],
            ['Source', metadata.source_path || metadata.class || metadata.reference_source || '—'],
        ].forEach(([label, value]) => {
            list.append(this.node('dt', '', label), this.node('dd', '', value));
        });

        const relationsTitle = this.node('p', 'cos-card__eyebrow', 'Relations');
        const relations = document.createElement('ul');
        relations.className = 'cos-architecture__relations';

        connections.slice(0, 24).forEach((connection) => {
            const item = document.createElement('li');
            item.className = 'cos-architecture__relation';
            item.append(
                this.node('code', '', `${connection.outgoing ? '→' : '←'} ${connection.relation}`),
                this.node('span', '', `${connection.neighbor.label || connection.neighbor.id} · ${connection.neighbor.type || ''}`),
            );
            relations.append(item);
        });

        const metadataDetails = document.createElement('details');
        const summary = this.node('summary', 'cos-button cos-button--ghost cos-button--sm', 'Raw metadata');
        const pre = this.node('pre', 'cos-architecture__metadata', this.safeJson(metadata));
        metadataDetails.append(summary, pre);

        const actions = document.createElement('div');
        actions.className = 'cos-action-bar cos-action-bar--start';

        const focus = this.node('button', 'cos-button cos-button--primary cos-button--sm', 'Focus');
        focus.type = 'button';
        focus.addEventListener('click', () => {
            this.localFocusNodeId = node.id();
            this.applyFilters(true);
            this.fit();
        });

        const collapse = this.node('button', 'cos-button cos-button--ghost cos-button--sm', this.collapsed.has(node.id()) ? 'Expand' : 'Collapse neighbors');
        collapse.type = 'button';
        collapse.addEventListener('click', () => {
            if (this.collapsed.has(node.id())) this.collapsed.delete(node.id());
            else this.collapsed.add(node.id());
            this.renderDetails(node);
            this.applyFilters(true);
        });

        actions.append(focus, collapse);
        this.detailsTarget.replaceChildren(
            this.node('p', 'cos-card__eyebrow', 'Selection'),
            title,
            list,
            relationsTitle,
            relations,
            metadataDetails,
            actions,
        );
    }

    connections(nodeId) {
        const nodes = new Map();
        this.elements.filter((item) => item.group === 'nodes').forEach((item) => nodes.set(item.data.id, item.data));

        return this.elements
            .filter((item) => item.group === 'edges' && (item.data.source === nodeId || item.data.target === nodeId))
            .map((edge) => {
                const outgoing = edge.data.source === nodeId;
                const neighborId = outgoing ? edge.data.target : edge.data.source;

                return {
                    outgoing,
                    relation: edge.data.relation || 'related',
                    neighbor: nodes.get(neighborId) || { id: neighborId, label: neighborId, type: 'unknown' },
                };
            });
    }

    async loadCytoscape() {
        if (typeof window.cytoscape === 'function') return window.cytoscape;

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

    setLoading(active) {
        if (this.hasLoadingTarget) this.loadingTarget.hidden = !active;
    }

    showError(message = '') {
        if (!this.hasErrorTarget) return;
        this.errorTarget.hidden = message === '';
        this.errorTarget.textContent = message;
    }

    safeJson(value) {
        try {
            return JSON.stringify(value ?? {}, null, 2);
        } catch {
            return '{}';
        }
    }

    node(tag, className = '', text = '') {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== '') node.textContent = String(text);
        return node;
    }
}
