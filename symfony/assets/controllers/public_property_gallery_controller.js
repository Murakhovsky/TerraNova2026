import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['main', 'open', 'thumb'];

    select(event) {
        const button = event.currentTarget;
        const image = button.dataset.image || '';
        const alt = button.dataset.alt || '';

        if (!image || !this.hasMainTarget) return;

        this.mainTarget.src = image;
        this.mainTarget.alt = alt;

        if (this.hasOpenTarget) {
            this.openTarget.href = image;
        }

        this.thumbTargets.forEach((thumb) => {
            const active = thumb === button;
            thumb.classList.toggle('is-active', active);
            thumb.setAttribute('aria-pressed', String(active));
        });
    }
}
