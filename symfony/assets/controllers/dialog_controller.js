import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['panel'];

    static values = {
        dismissible: { type: Boolean, default: true },
    };

    connect() {
        this.element.addEventListener('close', this.closed);
    }

    disconnect() {
        this.element.removeEventListener('close', this.closed);
    }

    close = () => {
        if (this.element instanceof HTMLDialogElement && this.element.open) {
            this.element.close('dismiss');
        }
    };

    cancel(event) {
        if (!this.dismissibleValue) {
            event.preventDefault();
        }
    }

    backdrop(event) {
        if (!this.dismissibleValue || event.target !== this.element) {
            return;
        }

        this.close();
    }

    closed = () => {
        const returnFocus = this.element.__cosReturnFocus;
        if (returnFocus instanceof HTMLElement && returnFocus.isConnected) {
            returnFocus.focus();
        }

        delete this.element.__cosReturnFocus;
    };
}
