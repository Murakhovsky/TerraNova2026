import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { csrf: String };

    create(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const payload = this.read(form);
        payload.enabled = payload.enabled !== '0';
        payload.allowed_roles = payload.allowed_roles ? payload.allowed_roles.split(/[ ,;]+/).filter(Boolean) : [];
        payload.conditions = this.json(payload.conditions || '[]');
        this.send('/api/v1/sales/admin/policies', 'POST', payload, form);
    }

    save(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const row = form.closest('[data-policy-row]');
        if (!row) return;
        const payload = this.read(form);
        payload.enabled = payload.enabled === '1';
        payload.conditions = this.json(payload.conditions || '[]');
        this.send(`/api/v1/sales/admin/policies/${encodeURIComponent(row.dataset.id || '')}`, 'PATCH', payload, form);
    }

    archive(event) {
        const row = event.currentTarget.closest('[data-policy-row]');
        const form = row?.querySelector('[data-edit]');
        if (!row || !form) return;
        this.send(`/api/v1/sales/admin/policies/${encodeURIComponent(row.dataset.id || '')}/archive`, 'POST', {
            configuration_version: Number(form.elements.configuration_version?.value || 0),
        }, form);
    }

    async preview(event) {
        event.preventDefault();
        const output = this.element.querySelector('[data-preview-result]');
        try {
            const result = await this.request('/api/v1/sales/admin/policies/preview', 'POST', this.read(event.currentTarget));
            if (output) output.textContent = `Decision: ${result.decision}\nMatched policy: ${result.matched_policy_name || result.matched_policy || 'none'}\nReason: ${result.reason}\nExecuted: ${result.executed ? 'yes' : 'no'}`;
        } catch (error) {
            if (output) output.textContent = error.message || 'Preview failed.';
        }
    }

    read(form) {
        return Object.fromEntries([...new FormData(form)].map(([key,value]) => [key, typeof value === 'string' ? value.trim() : value]));
    }

    json(value) {
        try { return JSON.parse(value); } catch { throw new Error('Conditions must be valid JSON.'); }
    }

    async send(url, method, payload, root) {
        const status = root.querySelector('[data-status], [data-create-status]');
        if (status) status.textContent = 'Saving…';
        try {
            await this.request(url, method, payload);
            if (status) status.textContent = 'Saved.';
            window.location.reload();
        } catch (error) {
            if (status) status.textContent = error.message || 'Request failed.';
        }
    }

    async request(url, method, payload) {
        const response = await fetch(url, {
            method,
            credentials:'same-origin',
            headers:{ Accept:'application/json', 'Content-Type':'application/json', 'X-CSRF-Token':this.csrfValue || '' },
            body:JSON.stringify(payload || {}),
        });
        const body = await response.json().catch(() => ({}));
        if (!response.ok || body.ok !== true) throw new Error(body.message || body.error || 'Request failed.');
        return body.data;
    }
}
