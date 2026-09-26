import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { csrf: String };
    static targets = ['status'];

    async create(event) {
        event.preventDefault();
        const data = Object.fromEntries(new FormData(event.currentTarget).entries());
        await this.run('/api/v1/sales/admin/pipelines', 'POST', data, (result) => {
            const id = result?.pipeline?.id || result?.id;
            window.location.assign(id ? `/sales/admin/pipelines/${encodeURIComponent(id)}` : '/sales/admin/pipelines');
        });
    }

    async clone(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const id = form.dataset.pipelineId || form.closest('[data-pipeline-id]')?.dataset.pipelineId || '';
        if (!id) return;
        const data = Object.fromEntries(new FormData(form).entries());
        await this.run(`/api/v1/sales/admin/pipelines/${encodeURIComponent(id)}/clone`, 'POST', data, (result) => {
            const cloneId = result?.pipeline?.id || result?.id;
            window.location.assign(cloneId ? `/sales/admin/pipelines/${encodeURIComponent(cloneId)}` : '/sales/admin/pipelines');
        });
    }

    async run(url, method, data, done) {
        this.setStatus('Saving…');
        try {
            const result = await this.request(url, method, data);
            this.setStatus('Saved.');
            done(result);
        } catch (error) {
            this.setStatus(error.message || 'Request failed.');
        }
    }

    async request(url, method, data) {
        const response = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-Token': this.csrfValue || '',
                'X-Idempotency-Key': globalThis.crypto?.randomUUID?.() || `sales-admin-${Date.now()}`,
            },
            body: JSON.stringify(data || {}),
        });
        const body = await response.json().catch(() => ({}));
        if (!response.ok || body.ok !== true) throw new Error(body.message || body.error || 'Request failed.');
        return body.data;
    }

    setStatus(message) {
        if (this.hasStatusTarget) this.statusTarget.textContent = message;
    }
}
