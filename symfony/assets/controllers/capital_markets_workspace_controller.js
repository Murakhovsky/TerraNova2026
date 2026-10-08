import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['density', 'columnToggle', 'age', 'simulationOutput'];

    connect() {
        this.refreshTimer = null;
        this.ageTimer = null;
        const storedDensity = window.localStorage.getItem('cos.capital_markets.table_density') || 'comfortable';
        if (this.hasDensityTarget) {
            this.densityTarget.value = storedDensity;
        }
        this.applyDensity(storedDensity);

        for (const table of this.tables()) {
            this.applyStoredColumns(table);
        }
        for (const toggle of this.columnToggleTargets) {
            const tableId = toggle.dataset.cmTableId || '';
            const column = toggle.dataset.cmColumn || '';
            if (!tableId || !column) {
                continue;
            }
            toggle.checked = !this.hiddenColumns(tableId).includes(column);
        }
        this.updateAge();
        this.ageTimer = window.setInterval(() => this.updateAge(), 1000);
    }

    disconnect() {
        if (this.refreshTimer !== null) {
            window.clearTimeout(this.refreshTimer);
            this.refreshTimer = null;
        }
        if (this.ageTimer !== null) {
            window.clearInterval(this.ageTimer);
            this.ageTimer = null;
        }
    }

    updateAge() {
        for (const target of this.ageTargets) {
            const raw = target.dataset.updatedAt || '';
            const timestamp = Date.parse(raw);
            if (!raw || Number.isNaN(timestamp)) {
                target.textContent = 'unavailable';
                continue;
            }
            const seconds = Math.max(0, Math.floor((Date.now() - timestamp) / 1000));
            target.textContent = seconds < 60
                ? seconds + 's'
                : (seconds < 3600 ? Math.floor(seconds / 60) + 'm' : Math.floor(seconds / 3600) + 'h');
        }
    }

    async simulateOpportunity(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const opportunityId = form.querySelector('[name="opportunity_id"]')?.value || '';
        const capital = form.querySelector('[name="capital"]')?.value || '';
        if (!opportunityId || !capital) {
            this.renderSimulation({ error: 'Opportunity and positive capital are required.' });
            return;
        }
        this.renderSimulation({ pending: true });
        try {
            const response = await fetch('/api/v1/capital-markets/portfolio/simulate-opportunity', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.element.dataset.csrf || '',
                },
                body: JSON.stringify({ opportunity_id: opportunityId, capital }),
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok || !payload.data) {
                throw new Error(payload.message || payload.error || 'Simulation failed.');
            }
            this.renderSimulation({ data: payload.data });
        } catch (error) {
            this.renderSimulation({ error: error instanceof Error ? error.message : 'Simulation failed.' });
        }
    }

    renderSimulation(state) {
        if (!this.hasSimulationOutputTarget) {
            return;
        }
        const output = this.simulationOutputTarget;
        output.replaceChildren();
        if (state.pending) {
            output.textContent = 'Running deterministic portfolio simulation…';
            return;
        }
        if (state.error) {
            output.textContent = state.error;
            return;
        }
        const data = state.data || {};
        const rows = [
            ['Decision', data.decision],
            ['Maximum approved capital', data.maximum_approved_capital],
            ['Capital after', data.capital_after],
            ['Gross exposure change', data.gross_exposure_change],
            ['Net exposure change', data.net_exposure_change],
            ['Margin change', data.margin_change],
            ['Liquidity change', data.liquidity_change],
            ['Risk score change', data.risk_score_change],
            ['Correlation effect', data.correlation_effect],
            ['Reasons', Array.isArray(data.reasons) ? data.reasons.join(', ') : data.reasons],
        ];
        const list = document.createElement('dl');
        list.className = 'row mb-0';
        for (const [label, value] of rows) {
            const term = document.createElement('dt');
            term.className = 'col-sm-5';
            term.textContent = label;
            const description = document.createElement('dd');
            description.className = 'col-sm-7';
            description.textContent = value === null || value === undefined || value === '' ? '—' : String(value);
            list.append(term, description);
        }
        output.append(list);
    }

    realtimeUpdate() {
        if (this.refreshTimer !== null) {
            window.clearTimeout(this.refreshTimer);
        }
        this.refreshTimer = window.setTimeout(() => {
            this.refreshTimer = null;
            if (window.Turbo && typeof window.Turbo.visit === 'function') {
                window.Turbo.visit(window.location.href, { action: 'replace' });
                return;
            }
            window.location.reload();
        }, 450);
    }

    changeDensity(event) {
        const density = event.currentTarget.value === 'compact' ? 'compact' : 'comfortable';
        window.localStorage.setItem('cos.capital_markets.table_density', density);
        this.applyDensity(density);
    }

    toggleColumn(event) {
        const toggle = event.currentTarget;
        const tableId = toggle.dataset.cmTableId || '';
        const column = toggle.dataset.cmColumn || '';
        if (!tableId || !column) {
            return;
        }

        const hidden = new Set(this.hiddenColumns(tableId));
        if (toggle.checked) {
            hidden.delete(column);
        } else {
            hidden.add(column);
        }
        window.localStorage.setItem(this.columnStorageKey(tableId), JSON.stringify(Array.from(hidden)));

        for (const table of this.tables()) {
            if ((table.dataset.cmTable || '') === tableId) {
                this.applyStoredColumns(table);
            }
        }
    }

    applyDensity(density) {
        for (const table of this.tables()) {
            table.classList.toggle('table-sm', density === 'compact');
        }
    }

    moveColumn(event) {
        const control = event.currentTarget;
        const tableId = control.dataset.cmTableId || '';
        const column = control.dataset.cmColumn || '';
        const direction = Number.parseInt(control.dataset.cmDirection || '0', 10);
        const table = this.tables().find((item) => (item.dataset.cmTable || '') === tableId);
        if (!table || !column || ![-1, 1].includes(direction)) {
            return;
        }

        const order = this.columnOrder(tableId, table);
        const index = order.indexOf(column);
        const target = index + direction;
        if (index < 0 || target < 0 || target >= order.length) {
            return;
        }
        [order[index], order[target]] = [order[target], order[index]];
        window.localStorage.setItem(this.columnOrderStorageKey(tableId), JSON.stringify(order));
        this.applyStoredColumns(table);
    }

    applyStoredColumns(table) {
        const tableId = table.dataset.cmTable || '';
        if (!tableId) {
            return;
        }

        const order = this.columnOrder(tableId, table);
        for (const row of table.rows) {
            const cells = Array.from(row.cells).filter((cell) => (cell.dataset.cmCol || '') !== '');
            const byColumn = new Map(cells.map((cell) => [cell.dataset.cmCol, cell]));
            for (const column of order) {
                const cell = byColumn.get(column);
                if (cell) {
                    row.append(cell);
                }
            }
        }

        const hidden = new Set(this.hiddenColumns(tableId));
        for (const cell of table.querySelectorAll('[data-cm-col]')) {
            cell.hidden = hidden.has(cell.dataset.cmCol || '');
        }
    }

    columnOrder(tableId, table) {
        const canonical = Array.from(table.querySelectorAll('thead [data-cm-col]'))
            .map((cell) => cell.dataset.cmCol || '')
            .filter((value) => value !== '');
        try {
            const parsed = JSON.parse(window.localStorage.getItem(this.columnOrderStorageKey(tableId)) || '[]');
            if (!Array.isArray(parsed)) {
                return canonical;
            }
            const stored = parsed.filter((value) => typeof value === 'string' && canonical.includes(value));
            return [...stored, ...canonical.filter((value) => !stored.includes(value))];
        } catch {
            return canonical;
        }
    }

    hiddenColumns(tableId) {
        try {
            const parsed = JSON.parse(window.localStorage.getItem(this.columnStorageKey(tableId)) || '[]');
            return Array.isArray(parsed) ? parsed.filter((value) => typeof value === 'string') : [];
        } catch {
            return [];
        }
    }

    tables() {
        return Array.from(this.element.querySelectorAll('table.table'));
    }

    columnStorageKey(tableId) {
        return 'cos.capital_markets.columns.' + tableId;
    }

    columnOrderStorageKey(tableId) {
        return 'cos.capital_markets.column_order.' + tableId;
    }
}
