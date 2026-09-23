import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { csrf: String };

    create(event) {
        event.preventDefault();
        const raw = Object.fromEntries(new FormData(event.currentTarget).entries());
        this.send('/api/v1/sales/integrations', 'POST', {
            integration_key: raw.integration_key,
            name: raw.name || undefined,
            credentials_reference: raw.credentials_reference || undefined,
            config: {},
        }, event.currentTarget);
    }

    update(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const card = form.closest('[data-integration-id]');
        if (!card) return;
        const data = Object.fromEntries(new FormData(form).entries());
        const payload = {
            name: data.name,
            status: data.status,
            configuration_version: Number(data.configuration_version),
        };
        if (data.credentials_reference) payload.credentials_reference = data.credentials_reference;
        this.send(`/api/v1/sales/integrations/${card.dataset.integrationId}`, 'PATCH', payload, card);
    }

    test(event) {
        const card = event.currentTarget.closest('[data-integration-id]');
        if (!card) return;
        this.send(`/api/v1/sales/integrations/${card.dataset.integrationId}/test`, 'POST', {}, card);
    }

    route(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const card = form.closest('[data-integration-id]');
        if (!card) return;
        this.send(`/api/v1/sales/integrations/${card.dataset.integrationId}/routes`, 'POST', Object.fromEntries(new FormData(form).entries()), card);
    }

    async send(url, method, data, root) {
        const status = root.querySelector?.('[data-integration-status]');
        if (status) status.textContent = 'Saving…';
        try {
            const response = await fetch(url, {
                method,
                credentials: 'same-origin',
                headers: {
                    Accept:'application/json',
                    'Content-Type':'application/json',
                    'X-CSRF-Token':this.csrfValue || '',
                    'X-Idempotency-Key':globalThis.crypto?.randomUUID?.() || `sales-integration-${Date.now()}`,
                },
                body: JSON.stringify(data || {}),
            });
            const body = await response.json().catch(() => ({}));
            if (!response.ok || body.ok !== true) throw new Error(body.message || body.error || 'Request failed.');
            if (status) status.textContent = 'Saved.';
            window.location.reload();
        } catch (error) {
            if (status) status.textContent = error.message || 'Request failed.';
        }
    }
}
