import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        dialogId: String,
    };

    open() {
        if (!this.hasDialogIdValue) {
            return;
        }

        const dialog = document.getElementById(this.dialogIdValue);
        if (!(dialog instanceof HTMLDialogElement) || dialog.open) {
            return;
        }

        dialog.__cosReturnFocus = this.element;
        dialog.showModal();
        dialog.dispatchEvent(new CustomEvent('cos:dialog-opened', { bubbles: true }));
    }
}
