import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['pin', 'viewport'];

    connect() {
        this.positionPins();
    }

    positionPins() {
        this.pinTargets.forEach((pin) => {
            const x = this.percent(pin.dataset.x);
            const y = this.percent(pin.dataset.y);
            pin.style.left = `${x}%`;
            pin.style.top = `${y}%`;
        });
    }

    percent(value) {
        const number = Number.parseFloat(value || '50');
        if (!Number.isFinite(number)) return 50;
        return Math.max(4, Math.min(96, number));
    }
}
