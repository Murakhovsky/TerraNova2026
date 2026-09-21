import { Controller } from '@hotwired/stimulus';
import { TabulatorFull as Tabulator } from 'tabulator-tables';

const SAFE_OPTIONS = new Set([
    'layout',
    'height',
    'minHeight',
    'maxHeight',
    'placeholder',
    'movableColumns',
    'resizableColumns',
    'rowHeight',
    'columnHeaderVertAlign',
]);

export default class extends Controller {
    static values = {
        rows: Array,
        columns: Array,
        options: Object,
    };

    connect() {
        this.table = new Tabulator(this.element, {
            ...this.safeOptions(),
            data: this.hasRowsValue ? this.rowsValue : [],
            columns: this.hasColumnsValue ? this.columnsValue : [],
        });

        this.table.on('rowClick', (_event, row) => {
            this.element.dispatchEvent(new CustomEvent('cos:tabulator-row', {
                bubbles: true,
                detail: { row: row.getData() },
            }));
        });
    }

    disconnect() {
        this.table?.destroy();
        this.table = null;
    }

    safeOptions() {
        if (!this.hasOptionsValue) {
            return {};
        }

        return Object.fromEntries(
            Object.entries(this.optionsValue).filter(([key]) => SAFE_OPTIONS.has(key)),
        );
    }
}
