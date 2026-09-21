import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        duration: { type: Number, default: 5000 },
        dismissible: { type: Boolean, default: true },
    };

    connect() {
        this.timer = null;

        if (this.durationValue > 0) {
            this.timer = window.setTimeout(() => this.dismiss(), this.durationValue);
        }
    }

    disconnect() {
        if (this.timer !== null) {
            window.clearTimeout(this.timer);
        }
    }

    dismiss() {
        if (!this.dismissibleValue && this.durationValue <= 0) {
            return;
        }

        this.element.classList.add('is-leaving');
        window.setTimeout(() => this.element.remove(), 140);
    }
}
