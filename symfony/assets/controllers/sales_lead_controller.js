import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        leadId: String,
        csrf: String,
    };

    async status(event) {
        const status = event.currentTarget.dataset.status || '';
        if (!status) return;
        await this.run('status', { status });
        window.setTimeout(() => window.location.reload(), 250);
    }

    async owner(event) {
        const ownerId = event.currentTarget.value || '';
        if (!ownerId) return;
        await this.run('owner', { owner_id: ownerId });
    }

    async convert() {
        const result = await this.run('deal');
        const dealId = result.case_id || result.deal_id;
        if (dealId) {
            window.location.assign(`/sales/deals/${encodeURIComponent(dealId)}`);
            return;
        }
        window.location.reload();
    }

    async followup() {
        const dueAt = window.prompt('Follow-up date/time (YYYY-MM-DD HH:MM)');
        if (!dueAt) return;
        await this.run('followups', { due_at: dueAt, title: 'Lead follow-up' });
    }

    async run(operation, data = {}) {
        const id = encodeURIComponent(this.leadIdValue || '');
        if (!id) throw new Error('Lead id is missing.');

        const request = {
            status: { endpoint: `/api/v1/sales/leads/${id}`, method: 'PATCH' },
            owner: { endpoint: `/api/v1/sales/leads/${id}`, method: 'PATCH' },
            deal: { endpoint: `/api/v1/sales/leads/${id}/opportunity`, method: 'POST' },
            followups: { endpoint: `/api/v1/sales/leads/${id}/followups`, method: 'POST' },
        }[operation];

        if (!request) throw new Error('Unsupported Lead operation.');

        const statusTarget = this.element.querySelector('[data-sales-lead-status-text]');
        const controls = [...this.element.querySelectorAll('button, select')];
        controls.forEach((control) => control.setAttribute('disabled', 'disabled'));
        this.setStatus(statusTarget, 'Зберігаю…', 'loading');

        try {
            const result = await this.requestJson(request.endpoint, request.method, data);
            this.setStatus(statusTarget, 'Готово.', 'success');
            return result;
        } catch (error) {
            this.setStatus(statusTarget, error.message || 'Lead operation failed.', 'error');
            throw error;
        } finally {
            controls.forEach((control) => control.removeAttribute('disabled'));
        }
    }

    async requestJson(endpoint, method, data) {
        const headers = {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-Token': this.csrfValue || '',
            'X-Idempotency-Key': globalThis.crypto?.randomUUID?.()
                || `sales-lead-${Date.now()}-${Math.random().toString(16).slice(2)}`,
        };

        const response = await fetch(endpoint, {
            method,
            credentials: 'same-origin',
            headers,
            body: JSON.stringify(data || {}),
        });
        const payload = await response.json().catch(() => ({}));

        if (!response.ok || !payload.ok) {
            const error = new Error(payload.message || payload.error || 'Sales operation failed.');
            error.status = response.status;
            throw error;
        }

        return payload.data || payload;
    }

    setStatus(target, message = '', state = 'neutral') {
        if (!target) return;
        target.textContent = message;
        target.dataset.state = state;
    }
}
