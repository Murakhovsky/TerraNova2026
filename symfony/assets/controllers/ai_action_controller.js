import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        actionId: String,
        resourceId: String,
        entityKey: String,
    };

    invoke(event) {
        event.preventDefault();
        this.dispatchAction();
    }

    confirmed(event) {
        event.stopPropagation();
        this.dispatchAction();
    }

    dispatchAction() {
        this.element.dispatchEvent(new CustomEvent('cos:workspace-action', {
            bubbles: true,
            detail: {
                actionId: this.hasActionIdValue ? this.actionIdValue : '',
                resourceId: this.hasResourceIdValue ? this.resourceIdValue : '',
                entityKey: this.hasEntityKeyValue ? this.entityKeyValue : '',
                source: 'ai',
            },
        }));
    }
}
