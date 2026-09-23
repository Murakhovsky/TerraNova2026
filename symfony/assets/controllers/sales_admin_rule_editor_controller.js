import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        ruleId: String,
        version: Number,
        csrf: String,
        catalog: Object,
        current: Object,
    };

    static targets = [
        'basic',
        'advanced',
        'conditions',
        'actions',
        'json',
        'status',
        'previewPanel',
        'preview',
        'previewNote',
    ];

    connect() {
        this.editorMode = 'basic';
        this.populateTrigger();
        this.resetFromCurrent();
    }

    mode(event) {
        const mode = event.currentTarget.dataset.mode === 'advanced' ? 'advanced' : 'basic';
        if (mode === 'advanced') {
            this.jsonTarget.value = JSON.stringify(this.readBasic(), null, 2);
        } else {
            this.applyPayload(this.parseAdvanced());
        }

        this.editorMode = mode;
        this.basicTarget.hidden = mode !== 'basic';
        this.advancedTarget.hidden = mode !== 'advanced';
    }

    addCondition(event) {
        event?.preventDefault();
        this.conditionsTarget.append(this.conditionRow({}));
    }

    addAction(event) {
        event?.preventDefault();
        this.actionsTarget.append(this.actionRow({}));
    }

    async save() {
        let payload;
        try {
            payload = this.editorMode === 'advanced' ? this.parseAdvanced() : this.readBasic();
        } catch (error) {
            this.setStatus(error.message || 'Invalid rule configuration.', 'error');
            return;
        }

        payload.configuration_version = this.versionValue;
        this.setStatus('Saving rule…', 'loading');

        try {
            const result = await this.request('', 'PATCH', payload);
            const rule = result?.rule || result;
            this.versionValue = Number(rule?.configuration_version || this.versionValue);
            if (rule && typeof rule === 'object') {
                this.currentValue = rule;
                this.applyPayload(rule);
            }
            this.setStatus('Rule saved.', 'success');
        } catch (error) {
            this.setStatus(
                error.status === 409
                    ? 'Rule changed elsewhere. Reload before saving again.'
                    : (error.message || 'Rule save failed.'),
                'error',
            );
        }
    }

    async lifecycle(event) {
        const action = event.currentTarget.dataset.ruleLifecycle || '';
        if (!['activate', 'disable', 'archive', 'restore-system'].includes(action)) return;

        this.setStatus(`${action}…`, 'loading');
        try {
            await this.request(`/${action}`, 'POST', {
                configuration_version: this.versionValue,
            });
            window.location.reload();
        } catch (error) {
            this.setStatus(error.message || 'Lifecycle change failed.', 'error');
        }
    }

    async dryRun() {
        this.setStatus('Running dry-run…', 'loading');

        try {
            const result = await this.request('/dry-run', 'GET');
            this.renderPreview(result || {});
            this.previewPanelTarget.hidden = false;
            this.setStatus('Dry-run completed. No mutations executed.', 'success');
        } catch (error) {
            this.setStatus(error.message || 'Dry-run failed.', 'error');
        }
    }

    populateTrigger() {
        const select = this.element.querySelector('[data-rule-trigger]');
        if (!select) return;

        select.replaceChildren();
        const selected = String(
            this.currentValue.trigger_key
            || this.currentValue.trigger_type
            || this.currentValue.trigger
            || '',
        );

        (this.catalogValue.triggers || []).forEach((trigger) => {
            select.append(this.option(
                trigger.key || trigger.value || trigger.type || '',
                trigger.label || trigger.key || trigger.value || trigger.type || '',
                selected,
            ));
        });
    }

    resetFromCurrent() {
        this.applyPayload(this.currentValue || {});
    }

    applyPayload(payload = {}) {
        const name = this.element.querySelector('[data-rule-name]');
        const priority = this.element.querySelector('[data-rule-priority]');
        const trigger = this.element.querySelector('[data-rule-trigger]');
        const conditionMode = this.element.querySelector('[data-rule-condition-mode]');

        if (name) name.value = String(payload.name || '');
        if (priority) priority.value = String(payload.priority ?? 100);

        const triggerKey = String(payload.trigger_key || payload.trigger_type || payload.trigger || '');
        if (trigger && triggerKey) trigger.value = triggerKey;

        const flattened = this.flattenConditions(payload.conditions);
        if (conditionMode) conditionMode.value = flattened.mode;

        this.conditionsTarget.replaceChildren();
        flattened.items.forEach((condition) => this.conditionsTarget.append(this.conditionRow(condition)));
        if (flattened.items.length === 0) this.addCondition();

        const actions = Array.isArray(payload.actions)
            ? payload.actions
            : (Array.isArray(payload.effect?.actions) ? payload.effect.actions : []);

        this.actionsTarget.replaceChildren();
        actions.forEach((action) => this.actionsTarget.append(this.actionRow(action)));
        if (actions.length === 0) this.addAction();

        this.jsonTarget.value = JSON.stringify(this.readBasic(), null, 2);
    }

    flattenConditions(raw) {
        if (Array.isArray(raw)) return { mode: 'all', items: raw };
        if (raw && typeof raw === 'object') {
            if (Array.isArray(raw.all)) return { mode: 'all', items: raw.all };
            if (Array.isArray(raw.any)) return { mode: 'any', items: raw.any };
            if ('field' in raw || 'operator' in raw) return { mode: 'all', items: [raw] };
        }
        return { mode: 'all', items: [] };
    }

    conditionRow(condition = {}) {
        const row = this.node('div', 'cos-card cos-section-grid');
        row.dataset.conditionRow = '';

        const trigger = (this.catalogValue.triggers || []).find((item) => (
            (item.key || item.value || item.type || '') === (this.element.querySelector('[data-rule-trigger]')?.value || '')
        ));
        const field = this.selectField('Field', 'field', trigger?.fields || [], condition.field || '');
        const operator = this.selectField('Operator', 'operator', this.catalogValue.operators || [], condition.operator || condition.op || '');
        const value = this.inputField('Value', 'value', condition.value ?? '');
        const unit = this.selectField(
            'Unit',
            'unit',
            (this.catalogValue.durations || ['minutes', 'hours', 'days']).map((item) => typeof item === 'string' ? item : (item.value || item.key || '')),
            condition.unit || 'hours',
        );
        const remove = this.node('button', 'cos-button cos-button--ghost cos-button--sm', 'Remove');
        remove.type = 'button';
        remove.addEventListener('click', () => row.remove());

        row.append(field, operator, value, unit, remove);
        return row;
    }

    actionRow(action = {}) {
        const row = this.node('div', 'cos-card cos-section-grid');
        row.dataset.actionRow = '';

        const types = (this.catalogValue.actions || []).map((item) => (
            typeof item === 'string'
                ? item
                : (item.type || item.value || '')
        ));
        const modeValues = (this.catalogValue.execution_modes || ['AUTO', 'MANUAL'])
            .map((item) => typeof item === 'string' ? item : (item.value || item.type || ''));
        const riskValues = (this.catalogValue.risks || ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'])
            .map((item) => typeof item === 'string' ? item : (item.value || item.type || ''));

        const type = this.selectField('Action', 'type', types, action.type || '');
        const mode = this.selectField('Mode', 'execution_mode', modeValues, action.execution_mode || action.mode || 'AUTO');
        const risk = this.selectField('Risk', 'risk_level', riskValues, action.risk_level || action.risk || 'MEDIUM');
        const params = this.inputField(
            'Parameters JSON',
            'parameters',
            JSON.stringify(action.parameters || action.params || {}),
        );
        const remove = this.node('button', 'cos-button cos-button--ghost cos-button--sm', 'Remove');
        remove.type = 'button';
        remove.addEventListener('click', () => row.remove());

        row.append(type, mode, risk, params, remove);
        return row;
    }

    readBasic() {
        const mode = this.element.querySelector('[data-rule-condition-mode]')?.value === 'any' ? 'any' : 'all';
        const conditions = [...this.conditionsTarget.querySelectorAll('[data-condition-row]')]
            .map((row) => ({
                field: row.querySelector('[name="field"]')?.value || '',
                operator: row.querySelector('[name="operator"]')?.value || '',
                value: row.querySelector('[name="value"]')?.value || '',
                unit: row.querySelector('[name="unit"]')?.value || '',
            }))
            .filter((condition) => condition.field && condition.operator);

        const actions = [...this.actionsTarget.querySelectorAll('[data-action-row]')]
            .map((row) => {
                const raw = row.querySelector('[name="parameters"]')?.value || '{}';
                let parameters = {};
                try {
                    parameters = raw.trim() === '' ? {} : JSON.parse(raw);
                } catch {
                    throw new Error('Action parameters must be valid JSON.');
                }

                return {
                    type: row.querySelector('[name="type"]')?.value || '',
                    execution_mode: row.querySelector('[name="execution_mode"]')?.value || '',
                    risk_level: row.querySelector('[name="risk_level"]')?.value || '',
                    parameters,
                };
            })
            .filter((action) => action.type);

        return {
            name: this.element.querySelector('[data-rule-name]')?.value?.trim() || '',
            priority: Number(this.element.querySelector('[data-rule-priority]')?.value || 100),
            trigger_key: this.element.querySelector('[data-rule-trigger]')?.value || '',
            condition_mode: mode,
            conditions: { [mode]: conditions },
            actions,
        };
    }

    parseAdvanced() {
        try {
            const payload = JSON.parse(this.jsonTarget.value || '{}');
            if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
                throw new Error('Advanced rule JSON must be an object.');
            }
            return payload;
        } catch (error) {
            throw new Error(error.message || 'Advanced rule JSON is invalid.');
        }
    }

    renderPreview(result) {
        this.previewTarget.replaceChildren();

        const metrics = [
            ['Matched', result.matched ? 'Yes' : 'No'],
            ['Actions', String(result.action_count ?? result.actions?.length ?? 0)],
            ['Would mutate', result.would_mutate ? 'Yes' : 'No'],
            ['Safe', result.safe === false ? 'No' : 'Yes'],
        ];

        metrics.forEach(([label, value]) => {
            const card = this.node('article', 'cos-card');
            card.append(
                this.node('p', 'cos-card__eyebrow', label),
                this.node('strong', 'cos-card__title', value),
            );
            this.previewTarget.append(card);
        });

        this.previewNoteTarget.textContent = result.reason || result.note || 'Dry-run uses read-only evaluation.';
    }

    selectField(label, name, values, selected) {
        const wrapper = this.node('label', 'cos-field');
        wrapper.append(this.node('span', '', label));

        const select = this.node('select', 'cos-select');
        select.name = name;
        values.filter(Boolean).forEach((item) => {
            const value = typeof item === 'string' ? item : (item.value || item.key || item.type || '');
            const text = typeof item === 'string' ? item : (item.label || value);
            select.append(this.option(value, text, String(selected ?? '')));
        });

        wrapper.append(select);
        return wrapper;
    }

    inputField(label, name, value) {
        const wrapper = this.node('label', 'cos-field');
        wrapper.append(this.node('span', '', label));
        const input = this.node('input', 'cos-input');
        input.name = name;
        input.value = String(value ?? '');
        wrapper.append(input);
        return wrapper;
    }

    option(value, label, selected) {
        const option = document.createElement('option');
        option.value = String(value ?? '');
        option.textContent = String(label ?? value ?? '');
        option.selected = option.value === String(selected ?? '');
        return option;
    }

    node(tag, className = '', text = '') {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== '') node.textContent = String(text);
        return node;
    }

    async request(suffix, method, payload = null) {
        const options = {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-CSRF-Token': this.csrfValue || '',
            },
        };
        if (payload !== null && method !== 'GET') {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(payload);
        }

        const response = await fetch(
            `/api/v1/sales/admin/rules/${encodeURIComponent(this.ruleIdValue)}${suffix}`,
            options,
        );
        const body = await response.json().catch(() => ({}));
        if (!response.ok || body.ok !== true) {
            const error = new Error(body.message || body.error || 'Rule request failed.');
            error.status = response.status;
            throw error;
        }

        return body.data;
    }

    setStatus(message, state = 'neutral') {
        this.statusTarget.textContent = message;
        this.statusTarget.dataset.state = state;
    }
}
