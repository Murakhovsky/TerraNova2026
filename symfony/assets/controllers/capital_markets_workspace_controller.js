import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['density', 'columnToggle', 'age', 'simulationOutput', 'historyChart', 'basisChart'];

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
        this.renderHistoricalCharts();
        this.renderBasisCharts();
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

    renderBasisCharts() {
        for (const host of this.basisChartTargets) {
            let rows;
            try {
                rows = JSON.parse(host.dataset.basisRows || '[]');
            } catch {
                host.textContent = 'Historical basis evidence could not be displayed.';
                continue;
            }
            if (!Array.isArray(rows) || rows.length === 0) {
                host.textContent = 'No comparable historical basis observations are available.';
                continue;
            }
            const groups = new Map();
            for (const row of rows) {
                if (!row || typeof row !== 'object') continue;
                const time = Date.parse(row.source_timestamp);
                const position = Number(row.basis_bps);
                if (!Number.isFinite(time) || !Number.isFinite(position)) continue;
                const key = [row.source_id || '', row.target_source_id || '', row.source_venue || '', row.target_venue || '', row.quote_asset || ''].join('|');
                if (!groups.has(key)) groups.set(key, { label: key, points: [] });
                groups.get(key).points.push({ time, position, raw: String(row.basis_bps), timestamp: row.source_timestamp });
            }
            const series = [...groups.values()].filter(group => group.points.length >= 2);
            host.replaceChildren();
            if (series.length === 0) {
                host.textContent = 'At least two recorded comparable observations from one source/venue/currency pair are needed.';
                continue;
            }
            const select = document.createElement('select');
            select.setAttribute('aria-label', 'Historical Basis evidence series');
            select.className = 'form-select form-select-sm mb-2';
            series.forEach((group, index) => {
                const option = document.createElement('option');
                option.value = String(index);
                option.textContent = group.label;
                select.append(option);
            });
            const display = document.createElement('div');
            const redraw = () => {
                display.replaceChildren();
                const points = [...series[Number(select.value) || 0].points].sort((a, b) => a.time - b.time).slice(-150);
                const low = Math.min(...points.map(p => p.position));
                const high = Math.max(...points.map(p => p.position));
                const start = points[0].time;
                const finish = points[points.length - 1].time;
                const x = p => finish === start ? 360 : 20 + (p.time - start) / (finish - start) * 680;
                const y = p => high === low ? 90 : 20 + (high - p.position) / (high - low) * 140;
                const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                svg.setAttribute('viewBox', '0 0 720 180');
                svg.setAttribute('width', '100%');
                svg.setAttribute('role', 'img');
                svg.setAttribute('aria-label', 'Historical recorded basis in basis points');
                const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                path.setAttribute('d', points.map((p,i) => (i ? 'L' : 'M') + x(p).toFixed(2) + ',' + y(p).toFixed(2)).join(' '));
                path.setAttribute('fill','none');
                path.setAttribute('stroke','currentColor');
                path.setAttribute('stroke-width','2');
                svg.append(path);
                for (const p of points) {
                    const circle = document.createElementNS('http://www.w3.org/2000/svg','circle');
                    circle.setAttribute('cx',x(p).toFixed(2));
                    circle.setAttribute('cy',y(p).toFixed(2));
                    circle.setAttribute('r','2.5');
                    circle.setAttribute('fill','currentColor');
                    const title = document.createElementNS('http://www.w3.org/2000/svg','title');
                    title.textContent = p.timestamp + ': ' + p.raw + ' bps';
                    circle.append(title);
                    svg.append(circle);
                }
                display.append(svg);
                const caption = document.createElement('p');
                caption.className = 'small text-muted';
                caption.textContent = points.length + ' paired canonical observations. Calculations are server-owned; chart coordinates only are computed here.';
                display.append(caption);
            };
            select.addEventListener('change',redraw);
            host.append(select,display);
            redraw();
        }
    }

    renderHistoricalCharts() {
        for (const host of this.historyChartTargets) {
            let rows;
            try {
                rows = JSON.parse(host.dataset.historyRows || '[]');
            } catch {
                host.textContent = 'Historical chart data could not be read.';
                continue;
            }
            if (!Array.isArray(rows)) {
                host.textContent = 'Historical chart data is unavailable.';
                continue;
            }
            const groups = new Map();
            for (const row of rows) {
                if (!row || typeof row !== 'object') continue;
                const type = String(row.event_type || '');
                const metric = type === 'FUNDING_RATE' ? 'Funding rate'
                    : ['QUOTE', 'BBO'].includes(type) ? 'Price'
                    : ['CANDLE', 'REFERENCE_PRICE', 'MARK_PRICE', 'INDEX_PRICE'].includes(type) ? 'Price'
                    : null;
                if (!metric) continue;
                const variants = metric === 'Price' && ['QUOTE', 'BBO'].includes(type)
                    ? [['Price', row.value], ['Quoted spread (bps)', row.spread_bps]]
                    : [[metric, row.value]];
                for (const [label, raw] of variants) {
                    if (raw === null || raw === undefined || raw === '') continue;
                    const number = Number(raw);
                    const timestamp = Date.parse(row.timestamp);
                    if (!Number.isFinite(number) || !Number.isFinite(timestamp)) continue;
                    const key = [label, type, row.source_id || '', row.venue_id || '', row.quote_asset || '', row.mode || ''].join('|');
                    if (!groups.has(key)) {
                        groups.set(key, { label: [label, type, row.venue_id || 'No venue', row.quote_asset || 'No unit', row.mode || 'Unknown mode'].join(' · '), points: [] });
                    }
                    groups.get(key).points.push({ timestamp, number, raw: String(raw), sourceTime: row.timestamp });
                }
            }
            const viable = [...groups.values()].filter(group => group.points.length >= 2);
            host.replaceChildren();
            if (viable.length === 0) {
                host.textContent = 'At least two recorded observations of the same series are required for a historical chart.';
                continue;
            }
            const select = document.createElement('select');
            select.className = 'form-select form-select-sm mb-2';
            select.setAttribute('aria-label', 'Select canonical historical market series');
            viable.forEach((series, index) => {
                const option = document.createElement('option');
                option.value = String(index);
                option.textContent = series.label;
                select.append(option);
            });
            const graphic = document.createElement('div');
            const draw = () => {
                graphic.replaceChildren();
                const series = viable[Number(select.value) || 0];
                const points = [...series.points].sort((a, b) => a.timestamp - b.timestamp).slice(-150);
                const min = Math.min(...points.map(p => p.number));
                const max = Math.max(...points.map(p => p.number));
                const earliest = points[0].timestamp;
                const latest = points[points.length - 1].timestamp;
                const width = 720;
                const height = 180;
                const x = p => latest === earliest ? width / 2 : 20 + (p.timestamp - earliest) / (latest - earliest) * (width - 40);
                const y = p => max === min ? height / 2 : 20 + (max - p.number) / (max - min) * (height - 40);
                const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                svg.setAttribute('viewBox', '0 0 720 180');
                svg.setAttribute('width', '100%');
                svg.setAttribute('role', 'img');
                svg.setAttribute('aria-label', 'Recorded historical observations for ' + series.label);
                const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                path.setAttribute('d', points.map((point, index) => (index === 0 ? 'M' : 'L') + x(point).toFixed(2) + ',' + y(point).toFixed(2)).join(' '));
                path.setAttribute('stroke', 'currentColor');
                path.setAttribute('fill', 'none');
                path.setAttribute('stroke-width', '2');
                svg.append(path);
                for (const point of points) {
                    const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                    circle.setAttribute('cx', x(point).toFixed(2));
                    circle.setAttribute('cy', y(point).toFixed(2));
                    circle.setAttribute('r', '2.5');
                    circle.setAttribute('fill', 'currentColor');
                    const title = document.createElementNS('http://www.w3.org/2000/svg', 'title');
                    title.textContent = point.sourceTime + ': ' + point.raw;
                    circle.append(title);
                    svg.append(circle);
                }
                graphic.append(svg);
                const caption = document.createElement('p');
                caption.className = 'small text-muted';
                caption.textContent = points.length + ' observed points · ' + points[0].sourceTime + ' to ' + points[points.length - 1].sourceTime + '. Axis positioning is presentation-only; values and metrics remain canonical.';
                graphic.append(caption);
            };
            select.addEventListener('change', draw);
            host.append(select, graphic);
            draw();
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
