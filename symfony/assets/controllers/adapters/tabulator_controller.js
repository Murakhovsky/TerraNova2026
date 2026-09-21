import { Controller } from '@hotwired/stimulus';
import { TabulatorFull as Tabulator } from 'tabulator-tables';

export default class extends Controller {
    static values = {
        rows: Array,
        columns: Array,
        options: Object,
    };

    connect() {
        this.table = new Tabulator(this.element, {
            ...(this.hasOptionsValue ? this.optionsValue : {}),
            data: this.hasRowsValue ? this.rowsValue : [],
            columns: this.hasColumnsValue ? this.columnsValue : [],
        });
    }

    disconnect() {
        this.table?.destroy();
        this.table = null;
    }
}
