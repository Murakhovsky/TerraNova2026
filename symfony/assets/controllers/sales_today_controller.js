import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        csrf: String,
    };

    static targets = ['status'];

    async approval(event) {
        const button = event.currentTarget;
        const panel = button.closest('[data-sales-approval]');
        const approvalId = panel?.dataset.approvalId || '';
        const decision = button.dataset.decision || '';

        if (!approvalId || !['approve', 'reject'].includes(decision)) return;

        this.disable(panel, true);
        this.status(decision === 'approve' ? 'Approving…' : 'Rejecting…', 'loading');

        try {
            await this.post(
                `/api/v1/sales/approvals/${encodeURIComponent(approvalId)}/${decision}`,
                {},
            );
            panel.closest('[data-sales-today-item]')?.remove();
            this.status(decision === 'approve' ? 'Approved.' : 'Rejected.', 'success');
        } catch (error) {
            this.disable(panel, false);
            this.status(error.message || 'Approval decision failed.', 'error');
        }
    }

    async complete(event) {
        const button = event.currentTarget;
        const panel = button.closest('[data-deal-id][data-activity-id]');
        const dealId = panel?.dataset.dealId || '';
        const activityId = panel?.dataset.activityId || '';

        if (!dealId || !activityId) return;

        button.setAttribute('disabled', 'disabled');
        this.status('Completing activity…', 'loading');

        try {
            await this.post(
                `/api/v1/sales/opportunities/${encodeURIComponent(dealId)}/activities/${encodeURIComponent(activityId)}/complete`,
                {},
            );
            panel.closest('[data-sales-today-item]')?.remove();
            this.status('Activity completed.', 'success');
        } catch (error) {
            button.removeAttribute('disabled');
            this.status(error.message || 'Activity completion failed.', 'error');
        }
    }

    async reschedule(event) {
        const panel = event.currentTarget.closest('[data-deal-id][data-activity-id]');
        const dealId = panel?.dataset.dealId || '';
        const activityId = panel?.dataset.activityId || '';

        if (!dealId || !activityId) return;

        const dueAt = window.prompt('New due date/time (YYYY-MM-DD HH:MM)');
        if (!dueAt) return;

        this.status('Rescheduling…', 'loading');

        try {
            await this.post(
                `/api/v1/sales/opportunities/${encodeURIComponent(dealId)}/activities/${encodeURIComponent(activityId)}/reschedule`,
                { due_at: dueAt },
            );
            this.status('Activity rescheduled.', 'success');
            window.setTimeout(() => window.location.reload(), 250);
        } catch (error) {
            this.status(error.message || 'Reschedule failed.', 'error');
        }
    }

    async post(endpoint, data) {
        const response = await fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-Token': this.csrfValue || '',
                'X-Idempotency-Key': globalThis.crypto?.randomUUID?.()
                    || `sales-today-${Date.now()}-${Math.random().toString(16).slice(2)}`,
            },
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

    disable(root, disabled) {
        root?.querySelectorAll('button').forEach((button) => {
            if (disabled) button.setAttribute('disabled', 'disabled');
            else button.removeAttribute('disabled');
        });
    }

    status(message = '', state = 'neutral') {
        if (!this.hasStatusTarget) return;
        this.statusTarget.textContent = message;
        this.statusTarget.dataset.state = state;
    }
}
