import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        entityKey: String,
    };

    action(event) {
        event.preventDefault();

        const trigger = event.currentTarget;
        if (trigger.disabled || trigger.getAttribute('aria-disabled') === 'true') {
            return;
        }

        this.element.dispatchEvent(new CustomEvent('cos:workspace-action', {
            bubbles: true,
            detail: {
                actionId: trigger.dataset.workspaceActionId || '',
                entityKey: this.hasEntityKeyValue ? this.entityKeyValue : '',
            },
        }));
    }
}
