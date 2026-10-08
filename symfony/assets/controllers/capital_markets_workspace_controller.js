import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['density', 'columnToggle'];

    connect() {
        this.refreshTimer = null;
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

    applyStoredColumns(table) {
        const tableId = table.dataset.cmTable || '';
        if (!tableId) {
            return;
        }
        const hidden = new Set(this.hiddenColumns(tableId));
        for (const cell of table.querySelectorAll('[data-cm-col]')) {
            cell.hidden = hidden.has(cell.dataset.cmCol || '');
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
}
