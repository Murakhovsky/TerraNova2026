import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        warn: { type: Boolean, default: true },
        draftMode: { type: String, default: 'none' },
        autosaveDelay: { type: Number, default: 1200 },
        leaveMessage: { type: String, default: 'You have unsaved changes. Leave this form?' },
    };

    connect() {
        this.dirty = false;
        this.baseline = this.fingerprint();
        this.autosaveTimer = null;
        this.beforeUnload = this.beforeUnload.bind(this);
        this.beforeVisit = this.beforeVisit.bind(this);

        window.addEventListener('beforeunload', this.beforeUnload);
        document.addEventListener('turbo:before-visit', this.beforeVisit);
    }

    disconnect() {
        window.removeEventListener('beforeunload', this.beforeUnload);
        document.removeEventListener('turbo:before-visit', this.beforeVisit);
        window.clearTimeout(this.autosaveTimer);
    }

    change() {
        this.setDirty(this.fingerprint() !== this.baseline);

        if (this.dirty && this.draftModeValue === 'autosave') {
            window.clearTimeout(this.autosaveTimer);
            this.autosaveTimer = window.setTimeout(
                () => this.requestDraft(),
                Math.max(250, this.autosaveDelayValue),
            );
        }
    }

    submit() {
        this.markClean();
    }

    reset() {
        window.requestAnimationFrame(() => {
            this.baseline = this.fingerprint();
            this.setDirty(false);
        });
    }

    requestDraft(event = null) {
        event?.preventDefault();

        if (this.draftModeValue === 'none') {
            return;
        }

        this.element.dispatchEvent(new CustomEvent('cos:draft-save-requested', {
            bubbles: true,
            detail: { mode: this.draftModeValue },
        }));
    }

    markClean() {
        window.clearTimeout(this.autosaveTimer);
        this.baseline = this.fingerprint();
        this.setDirty(false);
    }

    beforeUnload(event) {
        if (!this.warnValue || !this.dirty) {
            return;
        }

        event.preventDefault();
        event.returnValue = '';
    }

    beforeVisit(event) {
        if (!this.warnValue || !this.dirty) {
            return;
        }

        if (!window.confirm(this.leaveMessageValue)) {
            event.preventDefault();
        }
    }

    setDirty(dirty) {
        if (this.dirty === dirty) {
            return;
        }

        this.dirty = dirty;
        this.element.dataset.formDirty = dirty ? 'true' : 'false';
        this.element.dispatchEvent(new CustomEvent('cos:form-dirty', {
            bubbles: true,
            detail: { dirty },
        }));
    }

    fingerprint() {
        const data = new FormData(this.element);
        const entries = [];

        for (const [name, value] of data.entries()) {
            if (value instanceof File) {
                entries.push([name, value.name, value.size, value.lastModified]);
            } else {
                entries.push([name, value]);
            }
        }

        entries.sort((a, b) => JSON.stringify(a).localeCompare(JSON.stringify(b)));

        return JSON.stringify(entries);
    }
}
