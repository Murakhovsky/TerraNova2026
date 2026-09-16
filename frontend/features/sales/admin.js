const requestJson = async (url, csrf, payload) => {
  const response = await fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': csrf,
      Accept: 'application/json',
    },
    body: JSON.stringify({ ...payload, csrf_token: csrf }),
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
      await requestJson('/api/sales/admin/teams', csrf, Object.fromEntries(new FormData(form).entries()));
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
      await requestJson(`/api/sales/admin/teams/${teamId}/members/${card.dataset.userId}`, csrf, data);
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
      await requestJson(`/api/sales/admin/users/${card.dataset.userId}/capabilities`, csrf, { capabilities });
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

initSalesTeamAdmin();
initSalesAgentAdmin();
