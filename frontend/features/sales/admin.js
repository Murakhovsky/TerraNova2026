const requestJson = async (url, csrf, payload, includeCsrfBody = false) => {
  const bodyPayload = includeCsrfBody ? { ...payload, csrf_token: csrf } : payload;
  const response = await fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': csrf,
      Accept: 'application/json',
    },
    body: JSON.stringify(bodyPayload),
  });
  const body = await response.json().catch(() => ({}));
  if (!response.ok || body.ok !== true) {
    const error = new Error(body.error || 'Request failed.');
    error.status = response.status;
    throw error;
  }
  return body.data;
};

const initSalesTeamAdmin = () => {
  const root = document.querySelector('[data-sales-team-admin]');
  if (!root) return;
  const csrf = root.dataset.csrf || '';

  root.querySelector('[data-create-team]')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    try {
      await requestJson('/api/sales/admin/teams', csrf, Object.fromEntries(new FormData(form).entries()), true);
      window.location.reload();
    } catch (error) {
      window.alert(error.message);
    }
  });

  root.querySelectorAll('[data-membership-form]').forEach((form) => form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const card = form.closest('[data-user-id]');
    const data = Object.fromEntries(new FormData(form).entries());
    if (!card || !data.team_id) return;
    data.assignment_enabled = form.querySelector('[name="assignment_enabled"]')?.checked === true;
    data.approval_enabled = form.querySelector('[name="approval_enabled"]')?.checked === true;
    const teamId = data.team_id;
    delete data.team_id;
    try {
      await requestJson(`/api/sales/admin/teams/${teamId}/members/${card.dataset.userId}`, csrf, data, true);
      window.location.reload();
    } catch (error) {
      window.alert(error.message);
    }
  }));

  root.querySelectorAll('[data-capabilities-form]').forEach((form) => form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const card = form.closest('[data-user-id]');
    if (!card) return;
    const capabilities = [...form.querySelectorAll('input[name="capabilities[]"]:checked')].map((input) => input.value);
    try {
      await requestJson(`/api/sales/admin/users/${card.dataset.userId}/capabilities`, csrf, { capabilities }, true);
    } catch (error) {
      window.alert(error.message);
    }
  }));
};

const initSalesAgentAdmin = () => {
  const root = document.querySelector('[data-sales-agent-admin]');
  if (!root) return;
  const name = root.dataset.agentName || '';
  const csrf = root.dataset.csrf || '';
  const post = (suffix, payload) => requestJson(`/api/sales/admin/agents/${encodeURIComponent(name)}${suffix}`, csrf, payload);

  const config = root.querySelector('[data-sales-agent-config]');
  config?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const status = root.querySelector('[data-sales-agent-status]');
    if (status) status.textContent = 'Saving…';
    const form = new FormData(config);
    const payload = {
      configuration_version: Number(form.get('configuration_version')),
      enabled: form.get('enabled') === '1',
      profile: String(form.get('profile') || ''),
      model: String(form.get('model') || ''),
      business_instructions: String(form.get('business_instructions') || ''),
      confidence_threshold: Number(form.get('confidence_threshold')),
      context_sources: form.getAll('context_sources'),
      allowed_actions: form.getAll('allowed_actions'),
    };
    try {
      const result = await post('', payload);
      if (status) status.textContent = 'Saved.';
      if (config.elements.configuration_version) config.elements.configuration_version.value = result.configuration_version;
    } catch (error) {
      if (status) status.textContent = error.status === 409 ? 'Configuration changed elsewhere. Reload required.' : error.message;
    }
  });

  const test = root.querySelector('[data-sales-agent-test]');
  test?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const status = root.querySelector('[data-sales-agent-test-status]');
    const output = root.querySelector('[data-sales-agent-test-result]');
    const form = new FormData(test);
    if (status) status.textContent = 'Running read-only test…';
    if (output) output.hidden = true;
    try {
      const result = await post('/test', {
        subject_type: String(form.get('subject_type') || 'deal'),
        subject_id: String(form.get('subject_id') || ''),
        question: String(form.get('question') || ''),
      });
      if (status) status.textContent = 'Completed. No actions were executed.';
      if (output) {
        output.textContent = JSON.stringify(result, null, 2);
        output.hidden = false;
      }
    } catch (error) {
      if (status) status.textContent = error.message;
    }
  });
};

const initSalesIntegrationAdmin = () => {
  const root = document.querySelector('[data-sales-integration-admin]');
  if (!root) return;
  const csrf = root.dataset.csrf || '';
  const post = (url, payload) => requestJson(url, csrf, payload, true);

  root.querySelector('[data-create-integration]')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const raw = Object.fromEntries(new FormData(form).entries());
    const payload = {
      integration_key: raw.integration_key,
      name: raw.name || undefined,
      credentials_reference: raw.credentials_reference || undefined,
      config: {},
    };
    try {
      await post('/api/sales/admin/integrations', payload);
      window.location.reload();
    } catch (error) {
      window.alert(error.message);
    }
  });

  root.querySelectorAll('[data-update-integration]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const card = form.closest('[data-integration-id]');
      if (!card) return;
      const data = Object.fromEntries(new FormData(form).entries());
      const payload = {
        name: data.name,
        status: data.status,
        configuration_version: Number(data.configuration_version),
      };
      if (data.credentials_reference) payload.credentials_reference = data.credentials_reference;
      try {
        await post(`/api/sales/admin/integrations/${card.dataset.integrationId}`, payload);
        window.location.reload();
      } catch (error) {
        window.alert(error.message);
      }
    });
  });

  root.querySelectorAll('[data-test-integration]').forEach((button) => {
    button.addEventListener('click', async () => {
      const card = button.closest('[data-integration-id]');
      if (!card) return;
      try {
        const result = await post(`/api/sales/admin/integrations/${card.dataset.integrationId}/test`, {});
        window.alert(`Health: ${result.health_status}${result.reason ? `\n${result.reason}` : ''}`);
        window.location.reload();
      } catch (error) {
        window.alert(error.message);
      }
    });
  });

  root.querySelectorAll('[data-route-form]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const card = form.closest('[data-integration-id]');
      if (!card) return;
      const data = Object.fromEntries(new FormData(form).entries());
      try {
        await post(`/api/sales/admin/integrations/${card.dataset.integrationId}/routes`, data);
        window.location.reload();
      } catch (error) {
        window.alert(error.message);
      }
    });
  });
};

const initSalesPipelineListAdmin = () => {
  document.querySelectorAll('[data-sales-admin-json]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const data = Object.fromEntries(new FormData(form).entries());
      const csrf = String(data.csrf_token || '');
      delete data.csrf_token;
      const status = form.querySelector('[data-admin-status]');
      try {
        const result = await requestJson(form.dataset.url || '', csrf, data);
        const id = result?.pipeline?.id;
        if (form.dataset.redirectRoot && id) {
          window.location.href = `${form.dataset.redirectRoot}${encodeURIComponent(id)}`;
          return;
        }
        window.location.reload();
      } catch (error) {
        if (status) status.textContent = error.message;
      }
    });
  });
};

const initSalesPipelineAdmin = () => {
  const root = document.querySelector('[data-sales-admin]');
  if (!root) return;

  const send = (url, csrf, payload) => requestJson(url, csrf, payload);

  root.querySelectorAll('[data-admin-json]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const data = Object.fromEntries(new FormData(form).entries());
      const csrf = String(data.csrf_token || '');
      delete data.csrf_token;
      ['version', 'pipeline_version', 'sort_order', 'probability_default'].forEach((key) => {
        if (key in data) data[key] = Number(data[key]);
      });
      if ('is_default' in data) data.is_default = data.is_default === '1';
      try {
        await send(form.dataset.url || '', csrf, data);
        window.location.reload();
      } catch (error) {
        const status = form.querySelector('[data-admin-status]');
        if (status) status.textContent = error.message;
      }
    });
  });

  const reorder = root.querySelector('[data-reorder]');
  reorder?.addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
      await send(reorder.dataset.url || '', reorder.dataset.csrf || '', {
        version: Number(reorder.dataset.version),
        stages: [...reorder.querySelectorAll('[data-stage-id]')].map((input) => ({
          id: input.dataset.stageId,
          sort_order: Number(input.value),
        })),
      });
      window.location.reload();
    } catch (error) {
      const status = reorder.querySelector('[data-admin-status]');
      if (status) status.textContent = error.message;
    }
  });

  const transitions = root.querySelector('[data-transitions]');
  transitions?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const graph = [...transitions.querySelectorAll('[data-edge]:checked')].map((input) => {
      const key = `${input.dataset.from}:${input.dataset.to}`;
      const approval = transitions.querySelector(`[data-approval-for="${key}"]`);
      return {
        from_stage_id: input.dataset.from,
        to_stage_id: input.dataset.to,
        requires_approval: approval?.checked === true,
        conditions: [],
      };
    });
    try {
      await send(transitions.dataset.url || '', transitions.dataset.csrf || '', {
        version: Number(transitions.dataset.version),
        transitions: graph,
      });
      window.location.reload();
    } catch (error) {
      const status = transitions.querySelector('[data-admin-status]');
      if (status) status.textContent = error.message;
    }
  });

  const revisions = root.querySelector('[data-revisions]');
  if (revisions) {
    fetch(revisions.dataset.url || '', {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
      .then(async (response) => ({ ok: response.ok, body: await response.json() }))
      .then(({ ok, body }) => {
        if (!ok) throw new Error(body.error || 'Revision history unavailable');
        const rows = Array.isArray(body.data) ? body.data : [];
        const list = revisions.querySelector('[data-revision-list]');
        const count = revisions.querySelector('[data-revision-count]');
        if (count) count.textContent = String(rows.length);
        if (!list) return;
        list.textContent = '';
        if (!rows.length) {
          const note = document.createElement('p');
          note.className = 'tn-sales-note';
          note.textContent = 'No revisions recorded yet.';
          list.append(note);
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
          head.append(strong, meta);
          reason.textContent = row.reason || row.entity_id || '';
          summary.textContent = 'Payload';
          pre.textContent = JSON.stringify({ before: row.before_payload, after: row.after_payload }, null, 2);
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
};

const initSalesPolicyAdmin = () => {
  const root = document.querySelector('[data-sales-policy-admin]');
  if (!root) return;
  const csrf = root.dataset.csrf || '';
  const read = (form) => Object.fromEntries(
    [...new FormData(form)].map(([key, value]) => [key, typeof value === 'string' ? value.trim() : value]),
  );
  const post = (url, payload) => requestJson(url, csrf, payload);

  root.querySelector('[data-create]')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const status = root.querySelector('[data-create-status]');
    try {
      const payload = read(event.currentTarget);
      payload.enabled = payload.enabled === '1';
      payload.allowed_roles = payload.allowed_roles ? payload.allowed_roles.split(/[ ,;]+/) : [];
      payload.conditions = JSON.parse(payload.conditions || '[]');
      await post('/api/sales/admin/policies', payload);
      window.location.reload();
    } catch (error) {
      if (status) status.textContent = error.message;
    }
  });

  root.querySelectorAll('[data-policy-row]').forEach((row) => {
    const form = row.querySelector('[data-edit]');
    const status = row.querySelector('[data-status]');
    form?.addEventListener('submit', async (event) => {
      event.preventDefault();
      try {
        const payload = read(form);
        payload.enabled = payload.enabled === '1';
        payload.conditions = JSON.parse(payload.conditions || '[]');
        await post(`/api/sales/admin/policies/${encodeURIComponent(row.dataset.id || '')}`, payload);
        window.location.reload();
      } catch (error) {
        if (status) status.textContent = error.message;
      }
    });
    row.querySelector('[data-archive]')?.addEventListener('click', async () => {
      try {
        await post(`/api/sales/admin/policies/${encodeURIComponent(row.dataset.id || '')}/archive`, {
          configuration_version: Number(form?.elements?.configuration_version?.value || 0),
        });
        window.location.reload();
      } catch (error) {
        if (status) status.textContent = error.message;
      }
    });
  });

  root.querySelector('[data-preview]')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const output = root.querySelector('[data-preview-result]');
    try {
      const result = await post('/api/sales/admin/policies/preview', read(event.currentTarget));
      if (output) {
        output.textContent = `Decision: ${result.decision}\nMatched policy: ${result.matched_policy_name || result.matched_policy || 'none'}\nReason: ${result.reason}\nExecuted: ${result.executed ? 'yes' : 'no'}`;
      }
    } catch (error) {
      if (output) output.textContent = error.message;
    }
  });
};

initSalesTeamAdmin();
initSalesAgentAdmin();
initSalesIntegrationAdmin();
initSalesPipelineListAdmin();
initSalesPipelineAdmin();
initSalesPolicyAdmin();
