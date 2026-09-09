import './workspace.css';

const escapeHtml = (value) => String(value ?? '')
  .replaceAll('&', '&amp;')
  .replaceAll('<', '&lt;')
  .replaceAll('>', '&gt;')
  .replaceAll('"', '&quot;');

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
    ? `<div class="tn-sales-intelligence-actions">${actions.map((action) => `
        <article>
          <div><strong>${escapeHtml(action.type || 'Action')}</strong><span>${escapeHtml(action.status || 'PROPOSED')}</span></div>
          <p>${escapeHtml(action.reason || action.description || 'Запропонована наступна дія.')}</p>
          <small>Risk: ${escapeHtml(action.risk_level || '—')}</small>
        </article>`).join('')}</div>`
    : '<p class="tn-sales-empty">Активних COS actions для угоди немає.</p>';

  return `<div class="tn-sales-intelligence-grid">${decisionHtml}<div><h3>Proposed actions</h3>${actionsHtml}</div></div>`;
};

const loadIntelligence = async (root) => {
  const target = root.querySelector('[data-sales-intelligence]');
  const dealId = root.dataset.dealId;
  if (!target || !dealId) return;

  target.setAttribute('aria-busy', 'true');
  try {
    const response = await fetch(`/api/sales/deals/${encodeURIComponent(dealId)}/intelligence`, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });
    const payload = await response.json();
    if (!response.ok || !payload.ok) throw new Error(payload.error || 'Sales intelligence is unavailable.');
    target.innerHTML = intelligenceMarkup(payload.data);
  } catch (error) {
    target.innerHTML = `<div class="tn-ui-alert tn-ui-alert--warning"><span>${escapeHtml(error.message || 'Sales intelligence is unavailable.')}</span></div>`;
  } finally {
    target.removeAttribute('aria-busy');
  }
};

const readForm = (form) => Object.fromEntries([...new FormData(form).entries()].map(([key, value]) => [key, typeof value === 'string' ? value.trim() : value]));

const postStageChange = async (dealId, stageId, csrf) => {
  const response = await fetch(`/api/sales/deals/${encodeURIComponent(dealId)}/stage`, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf || '', Accept: 'application/json' },
    body: JSON.stringify({ stage_id: stageId }),
  });
  const payload = await response.json();
  if (!response.ok || !payload.ok) throw new Error(payload.error || 'Stage change failed.');
  return payload.data || {};
};

const operationEndpoint = (dealId, operation) => {
  const suffix = {
    quick: 'quick',
    owner: 'owner',
    activity: 'activities',
    followup: 'followups',
    meeting: 'meetings',
  }[operation];
  return suffix ? `/api/sales/deals/${encodeURIComponent(dealId)}/${suffix}` : null;
};

const submitOperation = async (root, form) => {
  const operation = form.dataset.operation || '';
  const endpoint = operationEndpoint(root.dataset.dealId, operation);
  const status = form.querySelector('[data-sales-operation-status]');
  const button = form.querySelector('button[type="submit"]');
  if (!endpoint) return;

  button?.setAttribute('disabled', 'disabled');
  if (status) status.textContent = 'Зберігаю…';

  try {
    const response = await fetch(endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': root.dataset.csrf || '',
        Accept: 'application/json',
      },
      body: JSON.stringify(readForm(form)),
    });
    const payload = await response.json();
    if (!response.ok || !payload.ok) throw new Error(payload.error || 'Sales operation failed.');
    if (status) status.textContent = 'Готово.';
    window.setTimeout(() => window.location.reload(), 350);
  } catch (error) {
    if (status) status.textContent = error.message || 'Не вдалося виконати дію.';
  } finally {
    button?.removeAttribute('disabled');
  }
};

const initDealWorkspace = (root) => {
  loadIntelligence(root);
  root.querySelector('[data-sales-intelligence-refresh]')?.addEventListener('click', () => loadIntelligence(root));

  const stageForm = root.querySelector('[data-sales-stage-form]');
  const stageStatus = root.querySelector('[data-sales-stage-status]');
  stageForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = stageForm.querySelector('button[type="submit"]');
    const stageId = stageForm.querySelector('[data-sales-stage-select]')?.value || '';
    if (!stageId) return;
    button?.setAttribute('disabled', 'disabled');
    if (stageStatus) stageStatus.textContent = 'Зберігаю…';

    try {
      const result = await postStageChange(root.dataset.dealId, stageId, root.dataset.csrf || '');
      if (stageStatus) stageStatus.textContent = result.changed === false ? 'Stage вже актуальний.' : 'Stage змінено.';
      if (result.changed !== false) window.setTimeout(() => window.location.reload(), 350);
    } catch (error) {
      if (stageStatus) stageStatus.textContent = error.message || 'Не вдалося змінити stage.';
    } finally {
      button?.removeAttribute('disabled');
    }
  });

  root.querySelectorAll('[data-sales-operation-form]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      submitOperation(root, form);
    });
  });
};

const initSalesPipeline = (root) => {
  const status = root.querySelector('[data-sales-pipeline-status]');
  let draggedCard = null;

  root.querySelectorAll('[data-sales-deal-card]').forEach((card) => {
    card.addEventListener('dragstart', (event) => {
      draggedCard = card;
      card.classList.add('is-dragging');
      event.dataTransfer?.setData('text/plain', card.dataset.dealId || '');
      if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move';
    });
    card.addEventListener('dragend', () => {
      card.classList.remove('is-dragging');
      root.querySelectorAll('[data-sales-stage-dropzone]').forEach((zone) => zone.classList.remove('is-drop-target'));
      draggedCard = null;
    });
  });

  root.querySelectorAll('[data-sales-stage-dropzone]').forEach((zone) => {
    zone.addEventListener('dragover', (event) => {
      event.preventDefault();
      zone.classList.add('is-drop-target');
      if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
    });
    zone.addEventListener('dragleave', () => zone.classList.remove('is-drop-target'));
    zone.addEventListener('drop', async (event) => {
      event.preventDefault();
      zone.classList.remove('is-drop-target');
      const card = draggedCard;
      const dealId = card?.dataset.dealId || event.dataTransfer?.getData('text/plain') || '';
      const targetStageId = zone.dataset.stageId || '';
      if (!dealId || !targetStageId || card?.dataset.stageId === targetStageId) return;

      if (status) status.textContent = 'Змінюю stage…';
      root.setAttribute('aria-busy', 'true');
      try {
        const result = await postStageChange(dealId, targetStageId, root.dataset.csrf || '');
        if (status) status.textContent = result.changed === false ? 'Stage уже актуальний.' : 'Stage змінено.';
        if (result.changed !== false) window.setTimeout(() => window.location.reload(), 250);
      } catch (error) {
        if (status) status.textContent = error.message || 'Не вдалося змінити stage.';
      } finally {
        root.removeAttribute('aria-busy');
      }
    });
  });
};

const initSalesWorkspace = () => {
  document.querySelectorAll('[data-sales-deal-workspace]').forEach(initDealWorkspace);
  document.querySelectorAll('[data-sales-pipeline-root]').forEach(initSalesPipeline);
  document.querySelectorAll('.tn-sales-click-row[data-href]').forEach((row) => {
    row.addEventListener('click', (event) => {
      if (event.target instanceof Element && event.target.closest('a,button,input,select,label')) return;
      window.location.assign(row.dataset.href);
    });
  });
};

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initSalesWorkspace, { once: true });
else initSalesWorkspace();