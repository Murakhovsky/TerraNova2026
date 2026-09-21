import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['rowCheckbox', 'selectAll', 'bulkBar', 'selectedCount'];

    connect() {
        this.sync();
    }

    toggleAll(event) {
        const checked = event.target.checked;

        for (const checkbox of this.rowCheckboxTargets) {
            checkbox.checked = checked;
        }

        this.sync();
    }

    selectionChanged(event) {
        const value = event.target.value;
        const checked = event.target.checked;

        for (const checkbox of this.rowCheckboxTargets) {
            if (checkbox.value === value) {
                checkbox.checked = checked;
            }
        }

        this.sync();
    }

    bulkAction(event) {
        event.preventDefault();

        const ids = this.selectedIds();
        if (ids.length === 0) {
            return;
        }

        this.element.dispatchEvent(new CustomEvent('cos:datagrid-bulk-action', {
            bubbles: true,
            detail: {
                actionId: event.currentTarget.dataset.actionId || '',
                ids,
            },
        }));
    }

    rowAction(event) {
        event.preventDefault();

        this.element.dispatchEvent(new CustomEvent('cos:datagrid-row-action', {
            bubbles: true,
            detail: {
                actionId: event.currentTarget.dataset.actionId || '',
                id: event.currentTarget.dataset.rowId || '',
            },
        }));
    }

    selectedIds() {
        return Array.from(new Set(
            this.rowCheckboxTargets
                .filter((checkbox) => checkbox.checked)
                .map((checkbox) => checkbox.value)
                .filter(Boolean),
        ));
    }

    sync() {
        const ids = this.selectedIds();
        const selected = ids.length;
        const allIds = Array.from(new Set(
            this.rowCheckboxTargets.map((checkbox) => checkbox.value).filter(Boolean),
        ));
        const total = allIds.length;

        if (this.hasSelectedCountTarget) {
            this.selectedCountTarget.textContent = String(selected);
        }

        if (this.hasBulkBarTarget) {
            this.bulkBarTarget.hidden = selected === 0;
        }

        if (this.hasSelectAllTarget) {
            this.selectAllTarget.checked = total > 0 && selected === total;
            this.selectAllTarget.indeterminate = selected > 0 && selected < total;
        }

        this.element.dataset.selectionCount = String(selected);
        this.element.dispatchEvent(new CustomEvent('cos:datagrid-selection-change', {
            bubbles: true,
            detail: { ids },
        }));
    }
}
