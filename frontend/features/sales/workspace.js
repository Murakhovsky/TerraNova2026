import './workspace.css';
import './workspace-v063.css';
import './workspace-v065.css';

const escapeHtml = (value) => String(value ?? '')
  .replaceAll('&', '&amp;')
  .replaceAll('<', '&lt;')
  .replaceAll('>', '&gt;')
  .replaceAll('"', '&quot;');

const readForm = (form) => Object.fromEntries(
  [...new FormData(form).entries()].map(([key, value]) => [key, typeof value === 'string' ? value.trim() : value]),
);

const setStatus = (target, message = '', state = 'neutral') => {
  if (!target) return;
  target.textContent = message;
  target.dataset.state = state;
};

const postJson = async (endpoint, data, csrf) => {
  const response = await fetch(endpoint, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf || '', Accept: 'application/json' },
    body: JSON.stringify(data || {}),
  });
  const payload = await response.json().catch(() => ({}));
  if (!response.ok || !payload.ok) {
    const error = new Error(payload.error || 'Sales operation failed.');
    error.status = response.status;
    throw error;
  }
  return payload.data || payload;
};

const getJson = async (endpoint, signal) => {
  const response = await fetch(endpoint, {
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
    signal,
  });
  const payload = await response.json().catch(() => ({}));
  if (!response.ok || !payload.ok) {
    const error = new Error(payload.error || 'Sales request failed.');
    error.status = response.status;
    throw error;
  }
  return payload.data || payload;
};

const postStageChange = (dealId, stageId, csrf) => postJson(
  `/api/sales/deals/${encodeURIComponent(dealId)}/stage`,
  { stage_id: stageId },
  csrf,
);

const operationEndpoint = (dealId, operation) => {
  const suffix = {
    quick: 'quick',
    owner: 'owner',
    activity: 'activities',
    followup: 'followups',
    meeting: 'meetings',
    message: 'messages',
  }[operation];
  return suffix ? `/api/sales/deals/${encodeURIComponent(dealId)}/${suffix}` : null;
};

const submitOperation = async (root, form) => {
  const endpoint = operationEndpoint(root.dataset.dealId, form.dataset.operation || '');
  const status = form.querySelector('[data-sales-operation-status]');
  const button = form.querySelector('button[type="submit"]');
  if (!endpoint) return;

  button?.setAttribute('disabled', 'disabled');
  setStatus(status, 'Зберігаю…', 'loading');
  try {
    await postJson(endpoint, readForm(form), root.dataset.csrf || '');
    setStatus(status, 'Готово.', 'success');
    window.setTimeout(() => window.location.reload(), 300);
  } catch (error) {
    setStatus(status, error.message || 'Не вдалося виконати дію.', 'error');
  } finally {
    button?.removeAttribute('disabled');
  }
};

const panelStatus = (panel) => {
  let status = panel.querySelector('[data-sales-control-status]');
  if (status) return status;
  status = document.createElement('small');
  status.dataset.salesControlStatus = '';
  status.className = 'tn-sales-control-status';
  panel.append(status);
  return status;
};

const actionButtons = (action) => {
  const id = escapeHtml(action.id || action.action_id || '');
  const status = String(action.status || '').toUpperCase();
  if (!id) return '';
  if (status === 'PENDING_APPROVAL') return '<small>Approval required</small>';
  if (['COMPLETED', 'REJECTED', 'RUNNING'].includes(status)) return '';
  return `<div class="tn-sales-quick-actions" data-sales-action data-action-id="${id}">
    <button type="button" class="tn-ui-button tn-ui-button--primary tn-ui-button--sm" data-decision="execute">Execute</button>
    ${status === 'PROPOSED' ? '<button type="button" class="tn-ui-button tn-ui-button--ghost tn-ui-button--sm" data-decision="dismiss">Dismiss</button>' : ''}
  </div>`;
};

const intelligenceMarkup = (data = {}) => {
  const decision = data.decision || null;
  const actions = Array.isArray(data.actions) ? data.actions : [];
  const decisionHtml = decision
    ? `<article class="tn-sales-intelligence-decision">
        <div><span>Decision</span><strong>${escapeHtml(decision.decision || decision.type || 'Assessment')}</strong></div>
        <p>${escapeHtml(decision.reason || 'Причину рішення не вказано.')}</p>
        <small>${decision.confidence == null ? 'Confidence: n/a' : `Confidence: ${Math.round(Number(decision.confidence) * 100)}%`}</small>
      </article>`
    : '<p class="tn-sales-empty">COS ще не сформував decision для цієї угоди.</p>';
  const actionsHtml = actions.length
    ? `<div class="tn-sales-intelligence-actions">${actions.map((action) => `<article>
        <div><strong>${escapeHtml(action.type || 'Action')}</strong><span>${escapeHtml(action.status || 'PROPOSED')}</span></div>
        <p>${escapeHtml(action.reason || action.description || 'Запропонована наступна дія.')}</p>
        <small>Risk: ${escapeHtml(action.risk_level || '—')}</small>${actionButtons(action)}
      </article>`).join('')}</div>`
    : '<p class="tn-sales-empty">Активних COS actions для угоди немає.</p>';
  return `<div class="tn-sales-intelligence-grid">${decisionHtml}<div><h3>Proposed actions</h3>${actionsHtml}</div></div>`;
};

const loadIntelligence = async (root) => {
  const target = root.querySelector('[data-sales-intelligence]');
  if (!target || !root.dataset.dealId) return;
  target.setAttribute('aria-busy', 'true');
  try {
    const data = await getJson(`/api/sales/deals/${encodeURIComponent(root.dataset.dealId)}/intelligence`);
    target.innerHTML = intelligenceMarkup(data);
    initActionControls(target, root.dataset.csrf || '');
  } catch (error) {
    target.innerHTML = `<div class="tn-ui-alert tn-ui-alert--warning"><span>${escapeHtml(error.message || 'Sales intelligence is unavailable.')}</span></div>`;
  } finally {
    target.removeAttribute('aria-busy');
  }
};

const initApprovalControls = (root, csrf) => root.querySelectorAll('[data-sales-approval]').forEach((panel) => {
  panel.querySelectorAll('[data-decision]').forEach((button) => button.addEventListener('click', async () => {
    const id = panel.dataset.approvalId || '';
    const decision = button.dataset.decision || '';
    if (!id || !['approve', 'reject'].includes(decision)) return;
    const status = panelStatus(panel);
    button.setAttribute('disabled', 'disabled');
    setStatus(status, decision === 'approve' ? 'Approving…' : 'Rejecting…', 'loading');
    try {
      await postJson(`/api/sales/approvals/${encodeURIComponent(id)}/${decision}`, {}, csrf);
      setStatus(status, decision === 'approve' ? 'Approved.' : 'Rejected.', 'success');
      window.setTimeout(() => panel.remove(), 250);
    } catch (error) {
      button.removeAttribute('disabled');
      setStatus(status, error.message || 'Approval decision failed.', 'error');
    }
  }));
});

const initActionControls = (root, csrf) => root.querySelectorAll('[data-sales-action]').forEach((panel) => {
  panel.querySelectorAll('[data-decision]').forEach((button) => button.addEventListener('click', async () => {
    const id = panel.dataset.actionId || '';
    const decision = button.dataset.decision || '';
    if (!id || !['execute', 'dismiss'].includes(decision)) return;
    const status = panelStatus(panel);
    button.setAttribute('disabled', 'disabled');
    setStatus(status, decision === 'execute' ? 'Queueing…' : 'Dismissing…', 'loading');
    try {
      await postJson(`/api/sales/actions/${encodeURIComponent(id)}/${decision}`, {}, csrf);
      panel.querySelectorAll('button').forEach((control) => control.setAttribute('disabled', 'disabled'));
      setStatus(status, decision === 'execute' ? 'Queued.' : 'Dismissed.', 'success');
    } catch (error) {
      button.removeAttribute('disabled');
      setStatus(status, error.message || 'COS action failed.', 'error');
    }
  }));
});

const initToday = (root) => {
  const csrf = root.dataset.csrf || '';
  const status = root.querySelector('[data-sales-today-status]');
  initApprovalControls(root, csrf);

  root.querySelectorAll('[data-sales-activity-complete]').forEach((button) => button.addEventListener('click', async () => {
    const panel = button.closest('[data-deal-id][data-activity-id]');
    if (!panel) return;
    button.setAttribute('disabled', 'disabled');
    setStatus(status, 'Completing activity…', 'loading');
    try {
      await postJson(`/api/sales/deals/${panel.dataset.dealId}/activities/${panel.dataset.activityId}/complete`, {}, csrf);
      panel.closest('.tn-sales-list-row')?.remove();
      setStatus(status, 'Activity completed.', 'success');
    } catch (error) {
      button.removeAttribute('disabled');
      setStatus(status, error.message || 'Activity completion failed.', 'error');
    }
  }));

  root.querySelectorAll('[data-sales-activity-reschedule]').forEach((button) => button.addEventListener('click', async () => {
    const panel = button.closest('[data-deal-id][data-activity-id]');
    if (!panel) return;
    const dueAt = window.prompt('New due date/time (YYYY-MM-DD HH:MM)');
    if (!dueAt) return;
    setStatus(status, 'Rescheduling…', 'loading');
    try {
      await postJson(`/api/sales/deals/${panel.dataset.dealId}/activities/${panel.dataset.activityId}/reschedule`, { due_at: dueAt }, csrf);
      setStatus(status, 'Activity rescheduled.', 'success');
      window.setTimeout(() => window.location.reload(), 250);
    } catch (error) {
      setStatus(status, error.message || 'Reschedule failed.', 'error');
    }
  }));
};

const initLeadInbox = (root) => {
  const csrf = root.dataset.csrf || '';
  root.querySelectorAll('[data-lead-id]').forEach((card) => {
    const id = card.dataset.leadId || '';
    const status = card.querySelector('[data-sales-lead-status-text]');
    const run = async (endpoint, data = {}) => {
      setStatus(status, 'Зберігаю…', 'loading');
      try {
        const result = await postJson(`/api/sales/leads/${id}/${endpoint}`, data, csrf);
        setStatus(status, 'Готово.', 'success');
        return result;
      } catch (error) {
        setStatus(status, error.message || 'Lead operation failed.', 'error');
        throw error;
      }
    };
    card.querySelectorAll('button[data-sales-lead-status]').forEach((button) => button.addEventListener('click', async () => {
      try { await run('status', { status: button.dataset.status }); window.setTimeout(() => window.location.reload(), 250); } catch (_) {}
    }));
    card.querySelector('[data-sales-lead-owner]')?.addEventListener('change', async (event) => {
      if (!event.target.value) return;
      try { await run('owner', { owner_id: event.target.value }); } catch (_) {}
    });
    card.querySelector('[data-sales-lead-deal]')?.addEventListener('click', async () => {
      try {
        const result = await run('deal');
        const dealId = result.case_id || result.deal_id;
        dealId ? window.location.assign(`/sales/deals/${dealId}`) : window.location.reload();
      } catch (_) {}
    });
    card.querySelector('[data-sales-lead-followup]')?.addEventListener('click', async () => {
      const dueAt = window.prompt('Follow-up date/time (YYYY-MM-DD HH:MM)');
      if (!dueAt) return;
      try { await run('followups', { due_at: dueAt, title: 'Lead follow-up' }); } catch (_) {}
    });
  });
};

const initDealWorkspace = (root) => {
  const csrf = root.dataset.csrf || '';
  loadIntelligence(root);
  initApprovalControls(root, csrf);
  root.querySelector('[data-sales-intelligence-refresh]')?.addEventListener('click', () => loadIntelligence(root));

  const stageForm = root.querySelector('[data-sales-stage-form]');
  const stageStatus = root.querySelector('[data-sales-stage-status]');
  stageForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = stageForm.querySelector('button[type="submit"]');
    const stageId = stageForm.querySelector('[data-sales-stage-select]')?.value || '';
    if (!stageId) return;
    button?.setAttribute('disabled', 'disabled');
    setStatus(stageStatus, 'Зберігаю…', 'loading');
    try {
      const result = await postStageChange(root.dataset.dealId, stageId, csrf);
      setStatus(stageStatus, result.changed === false ? 'Stage вже актуальний.' : 'Stage змінено.', 'success');
      if (result.changed !== false) window.setTimeout(() => window.location.reload(), 250);
    } catch (error) {
      const conflict = error.status === 409 || error.message === 'concurrent_stage_change';
      setStatus(stageStatus, conflict ? 'Stage вже змінив інший користувач. Оновлюю…' : error.message, conflict ? 'warning' : 'error');
      if (conflict) window.setTimeout(() => window.location.reload(), 700);
    } finally {
      button?.removeAttribute('disabled');
    }
  });

  root.querySelectorAll('[data-sales-operation-form]').forEach((form) => form.addEventListener('submit', (event) => {
    event.preventDefault();
    submitOperation(root, form);
  }));
};

const initSalesPipeline = (root) => {
  const status = root.querySelector('[data-sales-pipeline-status]');
  let draggedCard = null;
  root.querySelectorAll('[data-sales-deal-card]').forEach((card) => {
    card.addEventListener('dragstart', (event) => {
      draggedCard = card;
      card.classList.add('is-dragging');
      event.dataTransfer?.setData('text/plain', card.dataset.dealId || '');
    });
    card.addEventListener('dragend', () => {
      card.classList.remove('is-dragging');
      root.querySelectorAll('[data-sales-stage-dropzone]').forEach((zone) => zone.classList.remove('is-drop-target'));
      draggedCard = null;
    });
  });
  root.querySelectorAll('[data-sales-stage-dropzone]').forEach((zone) => {
    zone.addEventListener('dragover', (event) => { event.preventDefault(); zone.classList.add('is-drop-target'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('is-drop-target'));
    zone.addEventListener('drop', async (event) => {
      event.preventDefault();
      zone.classList.remove('is-drop-target');
      const card = draggedCard;
      const dealId = card?.dataset.dealId || event.dataTransfer?.getData('text/plain') || '';
      const targetStageId = zone.dataset.stageId || '';
      if (!dealId || !targetStageId || card?.dataset.stageId === targetStageId) return;
      setStatus(status, 'Змінюю stage…', 'loading');
      try {
        const result = await postStageChange(dealId, targetStageId, root.dataset.csrf || '');
        setStatus(status, result.changed === false ? 'Stage уже актуальний.' : 'Stage змінено.', 'success');
        if (result.changed !== false) window.setTimeout(() => window.location.reload(), 250);
      } catch (error) {
        const conflict = error.status === 409 || error.message === 'concurrent_stage_change';
        setStatus(status, conflict ? 'Stage змінив інший користувач. Оновлюю…' : error.message, conflict ? 'warning' : 'error');
        if (conflict) window.setTimeout(() => window.location.reload(), 700);
      }
    });
  });
};

const searchResultMarkup = (item) => {
  const tag = item.href ? 'a' : 'div';
  const href = item.href ? ` href="${escapeHtml(item.href)}"` : '';
  return `<${tag} class="tn-sales-global-search__result"${href} role="option">
    <span class="tn-sales-global-search__type">${escapeHtml(item.type || 'ITEM')}</span>
    <span><strong>${escapeHtml(item.title || 'Result')}</strong><small>${escapeHtml(item.meta || '')}</small></span>
  </${tag}>`;
};

const initSalesGlobalSearch = (root) => {
  const input = root.querySelector('[data-sales-global-search-input]');
  const form = root.querySelector('[data-sales-global-search-form]');
  const results = root.querySelector('[data-sales-global-search-results]');
  const status = root.querySelector('[data-sales-global-search-status]');
  const endpoint = root.dataset.endpoint || '/api/sales/search';
  if (!input || !results) return;

  let timer = null;
  let request = null;
  const hide = () => { results.hidden = true; results.innerHTML = ''; };
  const run = async () => {
    const query = input.value.trim();
    if (query.length < 2) { request?.abort(); hide(); setStatus(status); return; }
    request?.abort();
    request = new AbortController();
    setStatus(status, 'Searching…', 'loading');
    try {
      const data = await getJson(`${endpoint}?q=${encodeURIComponent(query)}&limit=6`, request.signal);
      const items = Array.isArray(data.items) ? data.items : [];
      results.innerHTML = items.length
        ? items.map(searchResultMarkup).join('')
        : '<p class="tn-sales-global-search__empty">Nothing found.</p>';
      results.hidden = false;
      const groups = data.groups || {};
      setStatus(status, `${Number(groups.deals || 0)} deals · ${Number(groups.leads || 0)} leads · ${Number(groups.people || 0)} people`, 'success');
    } catch (error) {
      if (error.name === 'AbortError') return;
      hide();
      setStatus(status, error.message || 'Sales search failed.', 'error');
    }
  };

  input.addEventListener('input', () => {
    window.clearTimeout(timer);
    timer = window.setTimeout(run, 180);
  });
  input.addEventListener('keydown', (event) => { if (event.key === 'Escape') hide(); });
  form?.addEventListener('submit', (event) => {
    if (input.value.trim().length >= 2 && !results.hidden) event.preventDefault();
  });
  document.addEventListener('keydown', (event) => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
      event.preventDefault();
      input.focus();
      input.select();
    }
  });
  document.addEventListener('click', (event) => { if (!root.contains(event.target)) hide(); });
};

const initSalesWorkspace = () => {
  document.querySelectorAll('[data-sales-deal-workspace]').forEach(initDealWorkspace);
  document.querySelectorAll('[data-sales-pipeline-root]').forEach(initSalesPipeline);
  document.querySelectorAll('[data-sales-today-root]').forEach(initToday);
  document.querySelectorAll('[data-sales-lead-inbox]').forEach(initLeadInbox);
  document.querySelectorAll('[data-sales-global-search]').forEach(initSalesGlobalSearch);
  document.querySelectorAll('.tn-sales-click-row[data-href]').forEach((row) => row.addEventListener('click', (event) => {
    if (event.target instanceof Element && event.target.closest('a,button,input,select,label')) return;
    window.location.assign(row.dataset.href);
  }));
};

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initSalesWorkspace, { once: true });
else initSalesWorkspace();
