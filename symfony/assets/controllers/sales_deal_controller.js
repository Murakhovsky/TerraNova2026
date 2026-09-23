import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        dealId: String,
        csrf: String,
    };

    static targets = ['intelligence'];

    connect() {
        this.refreshIntelligence();
    }

    async changeStage(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const stageId = form.querySelector('[data-sales-stage-select]')?.value || '';
        if (!stageId) return;

        await this.runForm(
            form,
            `/api/v1/sales/opportunities/${encodeURIComponent(this.dealIdValue)}/stage`,
            'POST',
            { stage_id: stageId },
        );
    }

    async operation(event) {
        event.preventDefault();

        const form = event.currentTarget;
        const operation = form.dataset.operation || '';
        const data = Object.fromEntries(new FormData(form).entries());
        const id = encodeURIComponent(this.dealIdValue || '');

        const request = {
            quick: { endpoint: `/api/v1/sales/opportunities/${id}`, method: 'PATCH' },
            owner: { endpoint: `/api/v1/sales/opportunities/${id}/owner`, method: 'POST' },
            activity: { endpoint: `/api/v1/sales/opportunities/${id}/activities`, method: 'POST' },
            followup: { endpoint: `/api/v1/sales/opportunities/${id}/next-action`, method: 'PUT' },
            meeting: { endpoint: `/api/v1/sales/opportunities/${id}/meetings`, method: 'POST' },
            message: { endpoint: `/api/v1/sales/opportunities/${id}/communications`, method: 'POST' },
        }[operation];

        if (!request) return;
        if (operation === 'activity' && data.activity_type === 'call') data.completed = true;

        await this.runForm(form, request.endpoint, request.method, data);
    }

    async approval(event) {
        const button = event.currentTarget;
        const panel = button.closest('[data-sales-approval]');
        const approvalId = panel?.dataset.approvalId || '';
        const decision = button.dataset.decision || '';
        if (!approvalId || !['approve', 'reject'].includes(decision)) return;

        this.disable(panel, true);
        try {
            await this.requestJson(
                `/api/v1/sales/approvals/${encodeURIComponent(approvalId)}/${decision}`,
                'POST',
                {},
            );
            panel.remove();
        } catch (error) {
            this.status(panel, error.message || 'Approval decision failed.', 'error');
            this.disable(panel, false);
        }
    }

    async decision(event) {
        const button = event.currentTarget;
        const panel = button.closest('[data-sales-action]');
        const actionId = panel?.dataset.actionId || '';
        const decision = button.dataset.decision || '';
        if (!actionId || !['execute', 'dismiss'].includes(decision)) return;

        this.disable(panel, true);
        try {
            await this.requestJson(
                `/api/v1/sales/actions/${encodeURIComponent(actionId)}/${decision}`,
                'POST',
                {},
            );
            await this.refreshIntelligence();
        } catch (error) {
            this.status(panel, error.message || 'COS action failed.', 'error');
            this.disable(panel, false);
        }
    }

    async refreshIntelligence() {
        if (!this.hasIntelligenceTarget || !this.dealIdValue) return;

        this.intelligenceTarget.setAttribute('aria-busy', 'true');
        try {
            const data = await this.getJson(
                `/api/v1/sales/opportunities/${encodeURIComponent(this.dealIdValue)}/intelligence`,
            );
            this.renderIntelligence(data);
        } catch (error) {
            const alert = this.node('div', 'cos-alert cos-alert--warning');
            alert.setAttribute('role', 'status');
            alert.append(
                this.node('strong', '', 'Sales intelligence unavailable'),
                this.node('span', '', error.message || 'Try again later.'),
            );
            this.intelligenceTarget.replaceChildren(alert);
        } finally {
            this.intelligenceTarget.removeAttribute('aria-busy');
        }
    }

    async runForm(form, endpoint, method, data) {
        const button = form.querySelector('button[type="submit"]');
        const status = form.querySelector('[data-sales-operation-status], [data-sales-stage-status]');

        button?.setAttribute('disabled', 'disabled');
        this.status(status, 'Зберігаю…', 'loading');

        try {
            await this.requestJson(endpoint, method, data);
            this.status(status, 'Готово.', 'success');
            window.setTimeout(() => window.location.reload(), 250);
        } catch (error) {
            this.status(status, error.message || 'Не вдалося виконати дію.', 'error');
            button?.removeAttribute('disabled');
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
                    || `sales-deal-${Date.now()}-${Math.random().toString(16).slice(2)}`,
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

    async getJson(endpoint) {
        const response = await fetch(endpoint, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        const payload = await response.json().catch(() => ({}));

        if (!response.ok || !payload.ok) {
            throw new Error(payload.message || payload.error || 'Sales request failed.');
        }

        return payload.data || payload;
    }

    renderIntelligence(data = {}) {
        const grid = this.node('div', 'cos-section-grid');
        const decision = data.decision && typeof data.decision === 'object' ? data.decision : null;
        const actions = Array.isArray(data.actions) ? data.actions : [];

        if (decision) {
            const card = this.node('article', 'cos-card');
            card.append(
                this.node('p', 'cos-card__eyebrow', 'Decision'),
                this.node('h3', 'cos-card__title', decision.decision || decision.type || 'Assessment'),
                this.node('p', 'cos-card__copy', decision.reason || 'Причину рішення не вказано.'),
            );
            grid.append(card);
        } else {
            grid.append(this.emptyState(
                'Decision ще не сформований',
                'COS не повернув активного рішення для цієї угоди.',
            ));
        }

        const actionsCard = this.node('section', 'cos-card');
        actionsCard.append(
            this.node('p', 'cos-card__eyebrow', 'Governed actions'),
            this.node('h3', 'cos-card__title', 'Proposed actions'),
        );
        const stack = this.node('div', 'cos-pattern-stack');

        if (actions.length === 0) {
            stack.append(this.emptyState(
                'Активних actions немає',
                'Нові пропозиції COS зʼявляться тут після наступного аналізу.',
            ));
        } else {
            actions.forEach((action) => stack.append(this.actionCard(action)));
        }

        actionsCard.append(stack);
        grid.append(actionsCard);
        this.intelligenceTarget.replaceChildren(grid);
    }

    actionCard(action = {}) {
        const id = String(action.id || action.action_id || '');
        const status = String(action.status || 'PROPOSED').toUpperCase();
        const card = this.node('article', 'cos-card');
        card.dataset.salesAction = '';
        card.dataset.actionId = id;

        card.append(
            this.node('p', 'cos-card__eyebrow', status),
            this.node('h4', 'cos-card__title', action.type || 'Action'),
            this.node('p', 'cos-card__copy', action.reason || action.description || 'Запропонована наступна дія.'),
        );

        if (id && !['COMPLETED', 'REJECTED', 'RUNNING', 'PENDING_APPROVAL'].includes(status)) {
            const bar = this.node('div', 'cos-action-bar cos-action-bar--start');
            bar.append(this.decisionButton('Execute', 'execute', 'cos-button cos-button--primary cos-button--sm'));
            if (status === 'PROPOSED') {
                bar.append(this.decisionButton('Dismiss', 'dismiss', 'cos-button cos-button--ghost cos-button--sm'));
            }
            card.append(bar);
        }

        return card;
    }

    decisionButton(label, decision, className) {
        const button = this.node('button', className, label);
        button.type = 'button';
        button.dataset.decision = decision;
        button.setAttribute('data-action', 'click->sales-deal#decision');
        return button;
    }

    emptyState(title, copy) {
        const node = this.node('div', 'cos-empty-state');
        node.append(
            this.node('h3', 'cos-empty-state__title', title),
            this.node('p', 'cos-empty-state__copy', copy),
        );
        return node;
    }

    node(tag, className = '', text = '') {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== '') node.textContent = String(text);
        return node;
    }

    disable(root, disabled) {
        root?.querySelectorAll('button, input, select, textarea').forEach((control) => {
            if (disabled) control.setAttribute('disabled', 'disabled');
            else control.removeAttribute('disabled');
        });
    }

    status(target, message = '', state = 'neutral') {
        if (!target) return;
        target.textContent = message;
        target.dataset.state = state;
    }
}
