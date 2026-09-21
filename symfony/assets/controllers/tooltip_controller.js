import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['tooltip'];

    show() {
        this.tooltipTarget.hidden = false;
    }

    hide() {
        this.tooltipTarget.hidden = true;
    }
}
