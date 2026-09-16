const pipelineAdminRoot = document.querySelector('[data-sales-admin]');

if (pipelineAdminRoot) {
  const send = async (url, csrf, data) => {
    const response = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrf,
      },
      body: JSON.stringify(data),
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(payload.error || 'Save failed');
    window.location.reload();
  };

  pipelineAdminRoot.querySelectorAll('[data-admin-json]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      const data = Object.fromEntries(new FormData(form));
      const csrf = data.csrf_token;
      delete data.csrf_token;

      ['version', 'pipeline_version', 'sort_order', 'probability_default'].forEach((key) => {
        if (key in data) data[key] = Number(data[key]);
      });
      data.is_default = data.is_default === '1';

      send(form.dataset.url, csrf, data).catch((error) => {
        const status = form.querySelector('[data-admin-status]');
        if (status) status.textContent = error.message;
      });
    });
  });

  const reorder = pipelineAdminRoot.querySelector('[data-reorder]');
  if (reorder) {
    reorder.addEventListener('submit', (event) => {
      event.preventDefault();
      send(reorder.dataset.url, reorder.dataset.csrf, {
        version: Number(reorder.dataset.version),
        stages: [...reorder.querySelectorAll('[data-stage-id]')].map((input) => ({
          id: input.dataset.stageId,
          sort_order: Number(input.value),
        })),
      }).catch((error) => {
        const status = reorder.querySelector('[data-admin-status]');
        if (status) status.textContent = error.message;
      });
    });
  }

  const transitions = pipelineAdminRoot.querySelector('[data-transitions]');
  if (transitions) {
    transitions.addEventListener('submit', (event) => {
      event.preventDefault();
      const edges = [...transitions.querySelectorAll('[data-edge]:checked')].map((edge) => {
        const key = `${edge.dataset.from}:${edge.dataset.to}`;
        const approval = transitions.querySelector(`[data-approval-for="${CSS.escape(key)}"]`);
        return {
          from_stage_id: edge.dataset.from,
          to_stage_id: edge.dataset.to,
          requires_approval: Boolean(approval?.checked),
          conditions: [],
        };
      });

      send(transitions.dataset.url, transitions.dataset.csrf, {
        version: Number(transitions.dataset.version),
        transitions: edges,
      }).catch((error) => {
        const status = transitions.querySelector('[data-admin-status]');
        if (status) status.textContent = error.message;
      });
    });
  }

  const revisions = pipelineAdminRoot.querySelector('[data-revisions]');
  if (revisions) {
    fetch(revisions.dataset.url, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
      .then((response) => response.json().then((payload) => ({ ok: response.ok, payload })))
      .then(({ ok, payload }) => {
        if (!ok) throw new Error(payload.error || 'Revision history unavailable');

        const rows = Array.isArray(payload.data) ? payload.data : [];
        const list = revisions.querySelector('[data-revision-list]');
        const count = revisions.querySelector('[data-revision-count]');
        if (count) count.textContent = String(rows.length);
        if (!list) return;

        list.textContent = '';
        if (!rows.length) {
          const message = document.createElement('p');
          message.className = 'tn-sales-note';
          message.textContent = 'No revisions recorded yet.';
          list.append(message);
          return;
        }

        rows.forEach((row) => {
          const article = document.createElement('article');
          const head = document.createElement('header');
          const strong = document.createElement('strong');
          const meta = document.createElement('small');
          const reason = document.createElement('p');
          const details = document.createElement('details');
          const summary = document.createElement('summary');
          const pre = document.createElement('pre');

          strong.textContent = `${row.configuration_type || 'CONFIG'} · ${row.action || 'UPDATE'}`;
          meta.textContent = `v${row.entity_version || 0} · ${row.actor_type || ''}:${row.actor_id || ''} · ${row.created_at || ''}`;
          reason.textContent = row.reason || row.entity_id || '';
          summary.textContent = 'Payload';
          pre.textContent = JSON.stringify({ before: row.before_payload, after: row.after_payload }, null, 2);

          head.append(strong, meta);
          details.append(summary, pre);
          article.append(head, reason, details);
          list.append(article);
        });
      })
      .catch((error) => {
        const list = revisions.querySelector('[data-revision-list]');
        if (list) list.textContent = error.message;
      });
  }
}
