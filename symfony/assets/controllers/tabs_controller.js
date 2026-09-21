import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['tab', 'panel'];

    static values = {
        active: String,
    };

    connect() {
        const requested = this.hasActiveValue ? this.activeValue : '';
        const first = this.tabTargets.find((tab) => !tab.disabled)?.dataset.tabKey || '';
        this.activate(requested || first, false);
    }

    select(event) {
        const key = event.currentTarget.dataset.tabKey || '';
        this.activate(key, true);
    }

    keydown(event) {
        const allowed = this.tabTargets.filter((tab) => !tab.disabled);
        const current = allowed.indexOf(document.activeElement);

        if (current < 0 || allowed.length === 0) {
            return;
        }

        let next = current;
        if (event.key === 'ArrowRight') next = (current + 1) % allowed.length;
        else if (event.key === 'ArrowLeft') next = (current - 1 + allowed.length) % allowed.length;
        else if (event.key === 'Home') next = 0;
        else if (event.key === 'End') next = allowed.length - 1;
        else return;

        event.preventDefault();
        allowed[next].focus();
        this.activate(allowed[next].dataset.tabKey || '', true);
    }

    activate(key, emit) {
        const selected = this.tabTargets.find(
            (tab) => tab.dataset.tabKey === key && !tab.disabled,
        );
        if (!selected) {
            return;
        }

        this.activeValue = key;

        this.tabTargets.forEach((tab) => {
            const active = tab === selected;
            tab.setAttribute('aria-selected', String(active));
            tab.setAttribute('tabindex', active ? '0' : '-1');
            tab.classList.toggle('is-active', active);
        });

        this.panelTargets.forEach((panel) => {
            const active = panel.dataset.tabKey === key;
            panel.hidden = !active;
            panel.classList.toggle('is-active', active);
        });

        if (emit) {
            this.element.dispatchEvent(new CustomEvent('cos:tab-change', {
                bubbles: true,
                detail: { key },
            }));
        }
    }
}
