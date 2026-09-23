import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { csrf: String };
    static targets = ['status'];

    async create(event) {
        event.preventDefault();
        const data = Object.fromEntries(new FormData(event.currentTarget).entries());
        this.statusTarget.textContent = 'Creating…';

        try {
            const response = await fetch('/api/v1/sales/admin/rules', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.csrfValue || '',
                },
                body: JSON.stringify({
                    name: String(data.name || '').trim(),
                    code: String(data.code || '').trim(),
                    trigger_key: String(data.trigger_key || ''),
                    conditions: [],
                    actions: [{ type: String(data.action_type || '') }],
                    priority: 100,
                }),
            });
            const body = await response.json().catch(() => ({}));
            if (!response.ok || body.ok === false) throw new Error(body.message || body.error || 'Rule create failed.');
            const rule = body.data ?? body;
            window.location.assign(`/sales/admin/rules/${encodeURIComponent(rule.id)}`);
        } catch (error) {
            this.statusTarget.textContent = error.message || 'Rule create failed.';
        }
    }
}
