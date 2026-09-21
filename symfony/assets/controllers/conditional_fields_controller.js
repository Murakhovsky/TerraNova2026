import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['source', 'section'];

    connect() {
        this.update();
    }

    update() {
        if (!this.hasSourceTarget) {
            return;
        }

        const value = this.sourceTarget.value;

        for (const section of this.sectionTargets) {
            const allowed = (section.dataset.conditionalFieldsWhenValue || '')
                .split(',')
                .map((item) => item.trim())
                .filter(Boolean);

            section.hidden = allowed.length > 0 && !allowed.includes(value);
        }
    }
}
