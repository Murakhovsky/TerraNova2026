import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['trigger', 'menu'];

    toggle(event) {
        event.preventDefault();
        this.menuTarget.hidden ? this.open() : this.close();
    }

    open() {
        this.menuTarget.hidden = false;
        this.triggerTarget.setAttribute('aria-expanded', 'true');
        this.items()[0]?.focus();
    }

    close() {
        if (this.menuTarget.hidden) {
            return;
        }

        this.menuTarget.hidden = true;
        this.triggerTarget.setAttribute('aria-expanded', 'false');
    }

    escape(event) {
        if (this.menuTarget.hidden) {
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

    keydown(event) {
        const items = this.items();
        const current = items.indexOf(document.activeElement);

        if (items.length === 0 || current < 0) {
            return;
        }

        let next = current;
        if (event.key === 'ArrowDown') next = (current + 1) % items.length;
        else if (event.key === 'ArrowUp') next = (current - 1 + items.length) % items.length;
        else if (event.key === 'Home') next = 0;
        else if (event.key === 'End') next = items.length - 1;
        else return;

        event.preventDefault();
        items[next].focus();
    }

    items() {
        return Array.from(this.menuTarget.querySelectorAll(
            'a[href]:not([aria-disabled="true"]), button:not([disabled]), [role="menuitem"]:not([aria-disabled="true"])',
        ));
    }
}
