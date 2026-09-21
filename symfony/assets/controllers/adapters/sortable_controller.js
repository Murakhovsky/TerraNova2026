import { Controller } from '@hotwired/stimulus';
import Sortable from 'sortablejs';

export default class extends Controller {
    static values = {
        options: Object,
    };

    connect() {
        this.sortable = Sortable.create(
            this.element,
            this.hasOptionsValue ? this.optionsValue : {},
        );
    }

    disconnect() {
        this.sortable?.destroy();
        this.sortable = null;
    }
}
