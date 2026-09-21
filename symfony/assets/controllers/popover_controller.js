import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['trigger', 'panel'];

    toggle(event) {
        event.preventDefault();
        this.panelTarget.hidden ? this.open() : this.close();
    }

    open() {
        this.panelTarget.hidden = false;
        this.triggerTarget.setAttribute('aria-expanded', 'true');
        this.panelTarget.focus({ preventScroll: true });
    }

    close() {
        if (this.panelTarget.hidden) {
            return;
        }

        this.panelTarget.hidden = true;
        this.triggerTarget.setAttribute('aria-expanded', 'false');
    }

    escape(event) {
        if (this.panelTarget.hidden) {
            return;
        }

        event.preventDefault();
        this.close();
        this.triggerTarget.focus();
    }

    outside(event) {
        if (!this.element.contains(event.target)) {
            this.close();
        }
    }
}
