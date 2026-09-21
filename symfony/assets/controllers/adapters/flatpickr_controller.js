import { Controller } from '@hotwired/stimulus';
import flatpickr from 'flatpickr';

export default class extends Controller {
    static values = {
        options: Object,
    };

    connect() {
        this.picker = flatpickr(
            this.element,
            this.hasOptionsValue ? this.optionsValue : {},
        );
    }

    disconnect() {
        this.picker?.destroy();
        this.picker = null;
    }
}
