import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['stepUpInput', 'confirmButton'];

    static values = {
        stepUp: { type: Boolean, default: false },
    };

    connect() {
        this.validate();
    }

    validate() {
        if (!this.hasConfirmButtonTarget) {
            return;
        }

        const permitted = !this.stepUpValue
            || (this.hasStepUpInputTarget && this.stepUpInputTarget.value.trim() === 'CONFIRM');

        this.confirmButtonTarget.disabled = !permitted;
        this.confirmButtonTarget.setAttribute('aria-disabled', String(!permitted));
    }

    confirm(event) {
        event.preventDefault();

        if (this.hasConfirmButtonTarget && this.confirmButtonTarget.disabled) {
            return;
        }

        this.element.dispatchEvent(new CustomEvent('cos:confirm', {
            bubbles: true,
            detail: {
                id: this.element.id,
                stepUp: this.stepUpValue,
            },
        }));

        if (this.element instanceof HTMLDialogElement) {
            this.element.close('confirm');
        }
    }
}
