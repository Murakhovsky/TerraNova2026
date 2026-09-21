import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['query', 'category', 'entry', 'group', 'count', 'empty'];

    connect() {
        this.filter();
    }

    filter() {
        const query = this.hasQueryTarget ? this.queryTarget.value.trim().toLowerCase() : '';
        const category = this.hasCategoryTarget ? this.categoryTarget.value : 'all';
        let visible = 0;

        this.entryTargets.forEach((entry) => {
            const matchesQuery = query === '' || (entry.dataset.catalogSearch || '').includes(query);
            const matchesCategory = category === 'all' || entry.dataset.catalogCategory === category;
            const show = matchesQuery && matchesCategory;

            entry.hidden = !show;
            if (show) {
                visible += 1;
            }
        });

        this.groupTargets.forEach((group) => {
            const hasVisibleEntry = Array.from(group.querySelectorAll('[data-ui-catalog-target~="entry"]'))
                .some((entry) => !entry.hidden);
            group.hidden = !hasVisibleEntry;
        });

        if (this.hasCountTarget) {
            this.countTarget.textContent = String(visible);
        }

        if (this.hasEmptyTarget) {
            this.emptyTarget.hidden = visible !== 0;
        }
    }

    clear() {
        if (this.hasQueryTarget) {
            this.queryTarget.value = '';
        }

        if (this.hasCategoryTarget) {
            this.categoryTarget.value = 'all';
        }

        this.filter();

        if (this.hasQueryTarget) {
            this.queryTarget.focus();
        }
    }
}
