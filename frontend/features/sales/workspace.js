import './workspace.css';
import './workspace-v063.css';

const escapeHtml = (value) => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;');
const readForm = (form) => Object.fromEntries([...new FormData(form).entries()].map(([key, value]) => [key, typeof value === 'string' ? value.trim() : value]));

const postJson = async (endpoint, data, csrf) => {
  const response = await fetch(endpoint, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf || '', Accept: 'application/json' }, body: JSON.stringify(data || {}) });
  const payload = await response.json().catch(() => ({}));
  if (!response.ok || !payload.ok) { const error = new Error(payload.error || 'Sales operation failed.'); error.status = response.status; throw error; }
  return payload.data || payload;
};
const postStageChange = (dealId, stageId, csrf) => postJson(`/api/sales/deals/${encodeURIComponent(dealId)}/stage`, { stage_id: stageId }, csrf);
const operationEndpoint = (dealId, operation) => { const suffix = { quick: 'quick', owner: 'owner', activity: 'activities', followup: 'followups', meeting: 'meetings', message: 'messages' }[operation]; return suffix ? `/api/sales/deals/${encodeURIComponent(dealId)}/${suffix}` : null; };

const submitOperation = async (root, form) => {
  const endpoint = operationEndpoint(root.dataset.dealId, form.dataset.operation || ''); const status = form.querySelector('[data-sales-operation-status]'); const button = form.querySelector('button[type="submit"]'); if (!endpoint) return;
  button?.setAttribute('disabled', 'disabled'); if (status) status.textContent = 'Зберігаю…';
  try { await postJson(endpoint, readForm(form), root.dataset.csrf || ''); if (status) status.textContent = 'Готово.'; window.setTimeout(() => window.location.reload(), 300); }
  catch (error) { if (status) status.textContent = error.message || 'Не вдалося виконати дію.'; }
  finally { button?.removeAttribute('disabled'); }
};

const actionButtons = (action) => {
  const id = escapeHtml(action.id || action.action_id || ''); const status = String(action.status || '').toUpperCase(); if (!id) return '';
  if (status === 'PENDING_APPROVAL') return '<small>Approval required</small>'; if (['COMPLETED', 'REJECTED', 'RUNNING'].includes(status)) return '';
  return `<div class="tn-sales-quick-actions" data-sales-action data-action-id="${id}"><button type="button" class="tn-ui-button tn-ui-button--primary tn-ui-button--sm" data-decision="execute">Execute</button>${status === 'PROPOSED' ? '<button type="button" class="tn-ui-button tn-ui-button--ghost tn-ui-button--sm" data-decision="dismiss">Dismiss</button>' : ''}</div>`;
};
const intelligenceMarkup = (data = {}) => {
  const decision = data.decision || null; const actions = Array.isArray(data.actions) ? data.actions : [];
  const decisionHtml = decision ? `<article class="tn-sales-intelligence-decision"><div><span>Decision</span><strong>${escapeHtml(decision.decision || decision.type || 'Assessment')}</strong></div><p>${escapeHtml(decision.reason || 'Причину рішення не вказано.')}</p><small>${decision.confidence == null ? 'Confidence: n/a' : `Confidence: ${Math.round(Number(decision.confidence) * 100)}%`}</small></article>` : '<p class="tn-sales-empty">COS ще не сформував decision для цієї угоди.</p>';
  const actionsHtml = actions.length ? `<div class="tn-sales-intelligence-actions">${actions.map((action) => `<article><div><strong>${escapeHtml(action.type || 'Action')}</strong><span>${escapeHtml(action.status || 'PROPOSED')}</span></div><p>${escapeHtml(action.reason || action.description || 'Запропонована наступна дія.')}</p><small>Risk: ${escapeHtml(action.risk_level || '—')}</small>${actionButtons(action)}</article>`).join('')}</div>` : '<p class="tn-sales-empty">Активних COS actions для угоди немає.</p>';
  return `<div class="tn-sales-intelligence-grid">${decisionHtml}<div><h3>Proposed actions</h3>${actionsHtml}</div></div>`;
};
const loadIntelligence = async (root) => {
  const target = root.querySelector('[data-sales-intelligence]'); if (!target || !root.dataset.dealId) return; target.setAttribute('aria-busy', 'true');
  try { const response = await fetch(`/api/sales/deals/${encodeURIComponent(root.dataset.dealId)}/intelligence`, { credentials: 'same-origin', headers: { Accept: 'application/json' } }); const payload = await response.json(); if (!response.ok || !payload.ok) throw new Error(payload.error || 'Sales intelligence is unavailable.'); target.innerHTML = intelligenceMarkup(payload.data); initActionControls(target, root.dataset.csrf || ''); }
  catch (error) { target.innerHTML = `<div class="tn-ui-alert tn-ui-alert--warning"><span>${escapeHtml(error.message || 'Sales intelligence is unavailable.')}</span></div>`; }
  finally { target.removeAttribute('aria-busy'); }
};

const initApprovalControls = (root, csrf) => root.querySelectorAll('[data-sales-approval]').forEach((panel) => panel.querySelectorAll('[data-decision]').forEach((button) => button.addEventListener('click', async () => {
  const id = panel.dataset.approvalId || ''; const decision = button.dataset.decision || ''; if (!id || !['approve', 'reject'].includes(decision)) return; button.setAttribute('disabled', 'disabled');
  try { await postJson(`/api/sales/approvals/${encodeURIComponent(id)}/${decision}`, {}, csrf); panel.remove(); }
  catch (error) { button.removeAttribute('disabled'); window.alert(error.message || 'Approval decision failed.'); }
})));
const initActionControls = (root, csrf) => root.querySelectorAll('[data-sales-action]').forEach((panel) => panel.querySelectorAll('[data-decision]').forEach((button) => button.addEventListener('click', async () => {
  const id = panel.dataset.actionId || ''; const decision = button.dataset.decision || ''; if (!id || !['execute', 'dismiss'].includes(decision)) return; button.setAttribute('disabled', 'disabled');
  try { await postJson(`/api/sales/actions/${encodeURIComponent(id)}/${decision}`, {}, csrf); panel.innerHTML = '<small>State updated.</small>'; }
  catch (error) { button.removeAttribute('disabled'); window.alert(error.message || 'COS action failed.'); }
})));

const initToday = (root) => {
  const csrf = root.dataset.csrf || ''; const status = root.querySelector('[data-sales-today-status]'); initApprovalControls(root, csrf);
  root.querySelectorAll('[data-sales-activity-complete]').forEach((button) => button.addEventListener('click', async () => { const panel = button.closest('[data-deal-id][data-activity-id]'); if (!panel) return; button.setAttribute('disabled', 'disabled'); try { await postJson(`/api/sales/deals/${panel.dataset.dealId}/activities/${panel.dataset.activityId}/complete`, {}, csrf); panel.closest('.tn-sales-list-row')?.remove(); if (status) status.textContent = 'Activity completed.'; } catch (error) { button.removeAttribute('disabled'); if (status) status.textContent = error.message; } }));
  root.querySelectorAll('[data-sales-activity-reschedule]').forEach((button) => button.addEventListener('click', async () => { const panel = button.closest('[data-deal-id][data-activity-id]'); if (!panel) return; const dueAt = window.prompt('New due date/time (YYYY-MM-DD HH:MM)'); if (!dueAt) return; try { await postJson(`/api/sales/deals/${panel.dataset.dealId}/activities/${panel.dataset.activityId}/reschedule`, { due_at: dueAt }, csrf); if (status) status.textContent = 'Activity rescheduled.'; window.setTimeout(() => window.location.reload(), 250); } catch (error) { if (status) status.textContent = error.message; } }));
};
const initLeadInbox = (root) => {
  const csrf = root.dataset.csrf || '';
  root.querySelectorAll('[data-lead-id]').forEach((card) => { const id = card.dataset.leadId || ''; const status = card.querySelector('[data-sales-lead-status-text]'); const run = async (endpoint, data = {}) => { if (status) status.textContent = 'Зберігаю…'; try { const result = await postJson(`/api/sales/leads/${id}/${endpoint}`, data, csrf); if (status) status.textContent = 'Готово.'; return result; } catch (error) { if (status) status.textContent = error.message; throw error; } };
    card.querySelectorAll('button[data-sales-lead-status]').forEach((button) => button.addEventListener('click', async () => { try { await run('status', { status: button.dataset.status }); window.setTimeout(() => window.location.reload(), 250); } catch (_) {} }));
    card.querySelector('[data-sales-lead-owner]')?.addEventListener('change', async (event) => { if (!event.target.value) return; try { await run('owner', { owner_id: event.target.value }); } catch (_) {} });
    card.querySelector('[data-sales-lead-deal]')?.addEventListener('click', async () => { try { const result = await run('deal'); const dealId = result.case_id || result.deal_id; dealId ? window.location.assign(`/sales/deals/${dealId}`) : window.location.reload(); } catch (_) {} });
    card.querySelector('[data-sales-lead-followup]')?.addEventListener('click', async () => { const dueAt = window.prompt('Follow-up date/time (YYYY-MM-DD HH:MM)'); if (!dueAt) return; try { await run('followups', { due_at: dueAt, title: 'Lead follow-up' }); } catch (_) {} });
  });
};

const initDealWorkspace = (root) => {
  const csrf = root.dataset.csrf || ''; loadIntelligence(root); initApprovalControls(root, csrf); root.querySelector('[data-sales-intelligence-refresh]')?.addEventListener('click', () => loadIntelligence(root));
  const stageForm = root.querySelector('[data-sales-stage-form]'); const stageStatus = root.querySelector('[data-sales-stage-status]');
  stageForm?.addEventListener('submit', async (event) => { event.preventDefault(); const button = stageForm.querySelector('button[type="submit"]'); const stageId = stageForm.querySelector('[data-sales-stage-select]')?.value || ''; if (!stageId) return; button?.setAttribute('disabled', 'disabled'); if (stageStatus) stageStatus.textContent = 'Зберігаю…'; try { const result = await postStageChange(root.dataset.dealId, stageId, csrf); if (stageStatus) stageStatus.textContent = result.changed === false ? 'Stage вже актуальний.' : 'Stage змінено.'; if (result.changed !== false) window.setTimeout(() => window.location.reload(), 250); } catch (error) { if (stageStatus) stageStatus.textContent = error.status === 409 || error.message === 'concurrent_stage_change' ? 'Stage вже змінив інший користувач. Оновлюю…' : error.message; if (error.status === 409 || error.message === 'concurrent_stage_change') window.setTimeout(() => window.location.reload(), 700); } finally { button?.removeAttribute('disabled'); } });
  root.querySelectorAll('[data-sales-operation-form]').forEach((form) => form.addEventListener('submit', (event) => { event.preventDefault(); submitOperation(root, form); }));
};
const initSalesPipeline = (root) => {
  const status = root.querySelector('[data-sales-pipeline-status]'); let draggedCard = null;
  root.querySelectorAll('[data-sales-deal-card]').forEach((card) => { card.addEventListener('dragstart', (event) => { draggedCard = card; card.classList.add('is-dragging'); event.dataTransfer?.setData('text/plain', card.dataset.dealId || ''); }); card.addEventListener('dragend', () => { card.classList.remove('is-dragging'); root.querySelectorAll('[data-sales-stage-dropzone]').forEach((z) => z.classList.remove('is-drop-target')); draggedCard = null; }); });
  root.querySelectorAll('[data-sales-stage-dropzone]').forEach((zone) => { zone.addEventListener('dragover', (event) => { event.preventDefault(); zone.classList.add('is-drop-target'); }); zone.addEventListener('dragleave', () => zone.classList.remove('is-drop-target')); zone.addEventListener('drop', async (event) => { event.preventDefault(); zone.classList.remove('is-drop-target'); const card = draggedCard; const dealId = card?.dataset.dealId || event.dataTransfer?.getData('text/plain') || ''; const targetStageId = zone.dataset.stageId || ''; if (!dealId || !targetStageId || card?.dataset.stageId === targetStageId) return; if (status) status.textContent = 'Змінюю stage…'; try { const result = await postStageChange(dealId, targetStageId, root.dataset.csrf || ''); if (status) status.textContent = result.changed === false ? 'Stage уже актуальний.' : 'Stage змінено.'; if (result.changed !== false) window.setTimeout(() => window.location.reload(), 250); } catch (error) { if (status) status.textContent = error.status === 409 || error.message === 'concurrent_stage_change' ? 'Stage змінив інший користувач. Оновлюю…' : error.message; if (error.status === 409 || error.message === 'concurrent_stage_change') window.setTimeout(() => window.location.reload(), 700); } }); });
};
const initSalesWorkspace = () => { document.querySelectorAll('[data-sales-deal-workspace]').forEach(initDealWorkspace); document.querySelectorAll('[data-sales-pipeline-root]').forEach(initSalesPipeline); document.querySelectorAll('[data-sales-today-root]').forEach(initToday); document.querySelectorAll('[data-sales-lead-inbox]').forEach(initLeadInbox); document.querySelectorAll('.tn-sales-click-row[data-href]').forEach((row) => row.addEventListener('click', (event) => { if (event.target instanceof Element && event.target.closest('a,button,input,select,label')) return; window.location.assign(row.dataset.href); })); };
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initSalesWorkspace, { once: true }); else initSalesWorkspace();
