import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        this.onInvalid = this.onInvalid.bind(this);
        this.element.addEventListener('invalid', this.onInvalid, true);

        const invalid = this.element.querySelector('[aria-invalid="true"]');
        if (invalid) {
            window.requestAnimationFrame(() => this.focusField(invalid));
        }
    }

    disconnect() {
        this.element.removeEventListener('invalid', this.onInvalid, true);
    }

    onInvalid(event) {
        const field = event.target;

        if (!(field instanceof HTMLElement)) {
            return;
        }

        field.setAttribute('aria-invalid', 'true');
        this.focusField(field);
    }

    focusField(field) {
        field.scrollIntoView({ block: 'center', behavior: 'smooth' });
        field.focus({ preventScroll: true });
    }
}
