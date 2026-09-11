import './rule-editor.css';

const parseJsonScript = (root, selector) => {
  const node = root.querySelector(selector);
  if (!node) return {};
  try { return JSON.parse(node.textContent || '{}'); } catch { return {}; }
};

const requestJson = async (url, { method = 'GET', data = null, csrf = '' } = {}) => {
  const options = { method, credentials: 'same-origin', headers: { Accept: 'application/json' } };
  if (data !== null) {
    options.headers['Content-Type'] = 'application/json';
    options.headers['X-CSRF-Token'] = csrf;
    options.body = JSON.stringify(data);
  }
  const response = await fetch(url, options);
  const payload = await response.json().catch(() => ({}));
  if (!response.ok || payload.ok === false) throw new Error(payload.error || `Request failed (${response.status})`);
  return payload.data ?? payload;
};

const initCreate = (root) => {
  const form = root.querySelector('[data-sales-rule-create]');
  if (!form) return;
  const status = form.querySelector('[data-sales-rule-create-status]');
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const data = Object.fromEntries(new FormData(form).entries());
    status.textContent = 'Creating…';
    try {
      const rule = await requestJson('/api/sales/admin/rules', {
        method: 'POST', csrf: root.dataset.csrf || '',
        data: {
          name: String(data.name || '').trim(),
          code: String(data.code || '').trim(),
          trigger_key: String(data.trigger_key || ''),
          conditions: [],
          actions: [{ type: String(data.action_type || '') }],
          priority: 100,
        },
      });
      window.location.assign(`/sales/admin/rules/${encodeURIComponent(rule.id)}`);
    } catch (error) { status.textContent = error.message; }
  });
};

const initEditor = (root) => {
  const catalog = parseJsonScript(root, '[data-sales-rule-catalog]');
  let current = parseJsonScript(root, '[data-sales-rule-current]');
  const ruleId = root.dataset.ruleId;
  if (!ruleId || !current.id) return;

  const triggerSelect = root.querySelector('[data-rule-trigger]');
  const conditionMode = root.querySelector('[data-rule-condition-mode]');
  const conditionsRoot = root.querySelector('[data-rule-conditions]');
  const actionsRoot = root.querySelector('[data-rule-actions]');
  const nameInput = root.querySelector('[data-rule-name]');
  const priorityInput = root.querySelector('[data-rule-priority]');
  const jsonInput = root.querySelector('[data-rule-json]');
  const statusMessage = root.querySelector('[data-rule-status-message]');
  const statusBadge = root.querySelector('[data-rule-status]');
  const csrf = root.dataset.csrf || '';
  let mode = 'basic';

  const triggerByKey = (key) => (catalog.triggers || []).find((item) => item.key === key) || null;
  const selectedTrigger = () => triggerByKey(triggerSelect.value);
  const actionByType = (type) => (catalog.actions || []).find((item) => item.type === type) || null;
  const escapeHtml = (value) => String(value ?? '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;');

  const flattenConditions = (conditions) => {
    if (Array.isArray(conditions)) return { mode: 'all', items: conditions };
    if (conditions && Array.isArray(conditions.all)) return { mode: 'all', items: conditions.all.filter((item) => item && item.field) };
    if (conditions && Array.isArray(conditions.any)) return { mode: 'any', items: conditions.any.filter((item) => item && item.field) };
    if (conditions && conditions.field) return { mode: 'all', items: [conditions] };
    return { mode: 'all', items: [] };
  };

  const renderTriggerOptions = () => {
    triggerSelect.innerHTML = (catalog.triggers || []).map((trigger) => `<option value="${escapeHtml(trigger.key)}">${escapeHtml(trigger.label)}</option>`).join('');
    triggerSelect.value = current.trigger_key || (catalog.triggers?.[0]?.key || '');
  };

  const fieldOptions = (selected = '') => (selectedTrigger()?.fields || []).map((field) => `<option value="${escapeHtml(field.value)}" ${field.value === selected ? 'selected' : ''}>${escapeHtml(field.label)}</option>`).join('');
  const operatorOptions = (selected = '=') => (catalog.operators || []).map((operator) => `<option value="${escapeHtml(operator.value)}" ${operator.value === selected ? 'selected' : ''}>${escapeHtml(operator.label)}</option>`).join('');
  const durationOptions = (selected = 'days') => (catalog.durations || []).map((unit) => `<option value="${escapeHtml(unit.value)}" ${unit.value === selected ? 'selected' : ''}>${escapeHtml(unit.label)}</option>`).join('');

  const addCondition = (condition = {}) => {
    const row = document.createElement('div');
    row.className = 'tn-sales-rule-condition';
    row.innerHTML = `
      <select class="tn-ui-input" data-condition-field>${fieldOptions(condition.field || '')}</select>
      <select class="tn-ui-input" data-condition-operator>${operatorOptions(condition.operator || '=')}</select>
      <input class="tn-ui-input" data-condition-value value="${escapeHtml(Array.isArray(condition.value) ? condition.value.join(', ') : (condition.value ?? ''))}" placeholder="value">
      <select class="tn-ui-input" data-condition-unit>${durationOptions(condition.unit || 'days')}</select>
      <button class="tn-ui-button" type="button" data-remove>Remove</button>`;
    row.querySelector('[data-remove]').addEventListener('click', () => row.remove());
    conditionsRoot.appendChild(row);
  };

  const addAction = (action = {}) => {
    const row = document.createElement('div');
    row.className = 'tn-sales-rule-action';
    const type = action.type || action.action_type || (catalog.actions?.[0]?.type || '');
    row.innerHTML = `
      <select class="tn-ui-input" data-action-type>${(catalog.actions || []).map((item) => `<option value="${escapeHtml(item.type)}" ${item.type === type ? 'selected' : ''}>${escapeHtml(item.label)}</option>`).join('')}</select>
      <select class="tn-ui-input" data-action-mode><option value="AUTO">AUTO</option><option value="MANUAL">MANUAL</option></select>
      <select class="tn-ui-input" data-action-risk><option>LOW</option><option>MEDIUM</option><option>HIGH</option><option>CRITICAL</option></select>
      <textarea class="tn-ui-input" rows="3" data-action-parameters placeholder='{"due_in_minutes":1440}'>${escapeHtml(JSON.stringify(action.parameters || {}, null, 2))}</textarea>
      <button class="tn-ui-button" type="button" data-remove>Remove</button>`;
    row.querySelector('[data-action-mode]').value = action.execution_mode || 'AUTO';
    row.querySelector('[data-action-risk]').value = action.risk_level || 'LOW';
    row.querySelector('[data-remove]').addEventListener('click', () => row.remove());
    row.querySelector('[data-action-type]').addEventListener('change', (event) => {
      const definition = actionByType(event.target.value);
      if (!definition) return;
      row.querySelector('[data-action-parameters]').value = JSON.stringify(definition.defaults || {}, null, 2);
    });
    actionsRoot.appendChild(row);
  };

  const readBasic = () => {
    const conditions = [...conditionsRoot.querySelectorAll('.tn-sales-rule-condition')].map((row) => {
      const field = row.querySelector('[data-condition-field]').value;
      const operator = row.querySelector('[data-condition-operator]').value;
      const valueInput = row.querySelector('[data-condition-value]');
      const fieldDef = (selectedTrigger()?.fields || []).find((item) => item.value === field);
      const condition = { field, operator };
      if (!['EXISTS','NOT_EXISTS'].includes(operator)) condition.value = valueInput.value;
      if (fieldDef?.type === 'duration') condition.unit = row.querySelector('[data-condition-unit]').value;
      return condition;
    });
    const actions = [...actionsRoot.querySelectorAll('.tn-sales-rule-action')].map((row) => {
      let parameters = {};
      try { parameters = JSON.parse(row.querySelector('[data-action-parameters]').value || '{}'); }
      catch { throw new Error('Action parameters must be valid JSON.'); }
      return {
        type: row.querySelector('[data-action-type]').value,
        execution_mode: row.querySelector('[data-action-mode]').value,
        risk_level: row.querySelector('[data-action-risk]').value,
        parameters,
      };
    });
    if (actions.length === 0) throw new Error('At least one THEN action is required.');
    return {
      name: nameInput.value.trim(), trigger_key: triggerSelect.value,
      condition_mode: conditionMode.value, conditions, actions,
      priority: Number(priorityInput.value || 100),
      configuration_version: Number(root.dataset.version || current.configuration_version || 0),
    };
  };

  const toAdvanced = () => {
    const definition = readBasic();
    delete definition.configuration_version;
    jsonInput.value = JSON.stringify(definition, null, 2);
  };

  const readPayload = () => {
    if (mode === 'basic') return readBasic();
    let definition;
    try { definition = JSON.parse(jsonInput.value || '{}'); } catch { throw new Error('Advanced JSON is invalid.'); }
    return { definition, configuration_version: Number(root.dataset.version || current.configuration_version || 0) };
  };

  const resetFromCurrent = () => {
    nameInput.value = current.name || '';
    priorityInput.value = current.priority || 100;
    renderTriggerOptions();
    conditionsRoot.innerHTML = '';
    const flattened = flattenConditions(current.conditions || {});
    conditionMode.value = flattened.mode;
    flattened.items.forEach(addCondition);
    actionsRoot.innerHTML = '';
    (current.actions || current.effect?.actions || []).forEach(addAction);
    if (!actionsRoot.children.length) addAction();
    toAdvanced();
    root.dataset.version = String(current.configuration_version || 0);
    if (statusBadge) { statusBadge.textContent = current.status || 'DRAFT'; statusBadge.dataset.status = current.status || 'DRAFT'; }
  };

  const save = async () => {
    statusMessage.textContent = 'Saving…';
    current = await requestJson(`/api/sales/admin/rules/${encodeURIComponent(ruleId)}`, { method:'POST', csrf, data: readPayload() });
    resetFromCurrent();
    statusMessage.textContent = `Saved as DRAFT · cfg v${current.configuration_version}`;
  };

  renderTriggerOptions();
  resetFromCurrent();

  triggerSelect.addEventListener('change', () => {
    conditionsRoot.innerHTML = '';
    addCondition();
  });
  root.querySelector('[data-rule-add-condition]')?.addEventListener('click', () => addCondition());
  root.querySelector('[data-rule-add-action]')?.addEventListener('click', () => addAction());
  root.querySelector('[data-rule-save]')?.addEventListener('click', () => save().catch((error) => { statusMessage.textContent = error.message; }));

  root.querySelectorAll('[data-rule-mode]').forEach((button) => button.addEventListener('click', () => {
    const next = button.dataset.ruleMode;
    if (next === 'advanced' && mode === 'basic') {
      try { toAdvanced(); } catch (error) { statusMessage.textContent = error.message; return; }
    }
    mode = next;
    root.querySelector('[data-rule-basic]').hidden = mode !== 'basic';
    root.querySelector('[data-rule-advanced]').hidden = mode !== 'advanced';
  }));

  root.querySelectorAll('[data-rule-lifecycle]').forEach((button) => button.addEventListener('click', async () => {
    const action = button.dataset.ruleLifecycle;
    statusMessage.textContent = `${action}…`;
    try {
      current = await requestJson(`/api/sales/admin/rules/${encodeURIComponent(ruleId)}/${action}`, {
        method:'POST', csrf, data:{ configuration_version:Number(root.dataset.version || 0) },
      });
      resetFromCurrent();
      statusMessage.textContent = `${current.status} · cfg v${current.configuration_version}`;
    } catch (error) { statusMessage.textContent = error.message; }
  }));

  root.querySelector('[data-rule-dry-run]')?.addEventListener('click', async () => {
    const panel = root.querySelector('[data-rule-preview-panel]');
    const output = root.querySelector('[data-rule-preview]');
    const note = root.querySelector('[data-rule-preview-note]');
    statusMessage.textContent = 'Running read-only preview…';
    try {
      const result = await requestJson(`/api/sales/admin/rules/${encodeURIComponent(ruleId)}/dry-run`);
      const metrics = [
        ['Matched deals', result.matched_deals], ['Would trigger', result.would_trigger],
        ['AUTO', result.would_auto], ['Approval required', result.would_require_approval],
        ['Denied', result.would_be_denied], ['Human only', result.would_be_human_only],
      ];
      output.innerHTML = metrics.map(([label,value]) => `<article class="tn-ui-kpi-card"><span>${escapeHtml(label)}</span><strong>${Number(value || 0)}</strong></article>`).join('');
      note.textContent = result.note || 'Read-only preview.';
      panel.hidden = false;
      statusMessage.textContent = 'Dry Run complete. No business state changed.';
    } catch (error) { statusMessage.textContent = error.message; }
  });
};

document.querySelectorAll('[data-sales-rule-list]').forEach(initCreate);
document.querySelectorAll('[data-sales-rule-editor]').forEach(initEditor);
