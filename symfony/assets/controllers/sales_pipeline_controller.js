import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { csrf: String };
    static targets = ['status'];

    draggedCard = null;

    dragStart(event) {
        const card = event.currentTarget;
        this.draggedCard = card;
        card.classList.add('is-dragging');
        event.dataTransfer?.setData('text/plain', card.dataset.dealId || '');
        if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move';
    }

    dragEnd(event) {
        event.currentTarget.classList.remove('is-dragging');
        this.element.querySelectorAll('[data-sales-stage-dropzone]').forEach((zone) => {
            zone.classList.remove('is-drop-target');
        });
        this.draggedCard = null;
    }

    dragOver(event) {
        event.preventDefault();
        event.currentTarget.classList.add('is-drop-target');
        if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
    }

    dragLeave(event) {
        event.currentTarget.classList.remove('is-drop-target');
    }

    async drop(event) {
        event.preventDefault();
        const zone = event.currentTarget;
        zone.classList.remove('is-drop-target');

        const card = this.draggedCard;
        const dealId = card?.dataset.dealId || event.dataTransfer?.getData('text/plain') || '';
        const stageId = zone.dataset.stageId || '';
        if (!dealId || !stageId || card?.dataset.stageId === stageId) return;

        await this.move(dealId, stageId);
    }

    async changeStage(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const dealId = form.querySelector('[name="deal_id"]')?.value || '';
        const stageId = form.querySelector('[name="stage_id"]')?.value || '';
        if (!dealId || !stageId) return;

        const button = form.querySelector('button[type="submit"]');
        button?.setAttribute('disabled', 'disabled');
        try {
            await this.move(dealId, stageId);
        } finally {
            button?.removeAttribute('disabled');
        }
    }

    async move(dealId, stageId) {
        this.setStatus('Змінюю stage…', 'loading');

        try {
            const result = await this.requestJson(
                `/api/v1/sales/opportunities/${encodeURIComponent(dealId)}/stage`,
                'POST',
                { stage_id: stageId },
            );
            this.setStatus(result.changed === false ? 'Stage уже актуальний.' : 'Stage змінено.', 'success');
            if (result.changed !== false) {
                window.setTimeout(() => window.location.reload(), 250);
            }
        } catch (error) {
            const conflict = error.status === 409 || error.message === 'concurrent_stage_change';
            this.setStatus(
                conflict ? 'Stage змінив інший користувач. Оновлюю…' : (error.message || 'Stage change failed.'),
                conflict ? 'warning' : 'error',
            );
            if (conflict) {
                window.setTimeout(() => window.location.reload(), 700);
            }
        }
    }

    async requestJson(endpoint, method, data) {
        const response = await fetch(endpoint, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-Token': this.csrfValue || '',
                'X-Idempotency-Key': globalThis.crypto?.randomUUID?.()
                    || `sales-pipeline-${Date.now()}-${Math.random().toString(16).slice(2)}`,
            },
            body: JSON.stringify(data || {}),
        });
        const payload = await response.json().catch(() => ({}));

        if (!response.ok || !payload.ok) {
            const error = new Error(payload.message || payload.error || 'Stage change failed.');
            error.status = response.status;
            throw error;
        }

        return payload.data || payload;
    }

    setStatus(message = '', state = 'neutral') {
        if (!this.hasStatusTarget) return;
        this.statusTarget.textContent = message;
        this.statusTarget.dataset.state = state;
    }
}
