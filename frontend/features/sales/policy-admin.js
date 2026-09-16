const policyAdminRoot = document.querySelector('[data-sales-policy-admin]');

if (policyAdminRoot) {
  const csrf = policyAdminRoot.dataset.csrf || '';
  const readForm = (form) => Object.fromEntries(
    [...new FormData(form)].map(([key, value]) => [key, typeof value === 'string' ? value.trim() : value]),
  );

  const post = async (url, payload) => {
    const response = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrf,
      },
      body: JSON.stringify(payload),
    });
    const body = await response.json().catch(() => ({}));
    if (!response.ok || !body.ok) throw new Error(body.error || 'Policy request failed.');
    return body.data;
  };

  policyAdminRoot.querySelector('[data-create]')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const status = policyAdminRoot.querySelector('[data-create-status]');
    try {
      const payload = readForm(event.currentTarget);
      payload.enabled = payload.enabled === '1';
      payload.allowed_roles = payload.allowed_roles ? payload.allowed_roles.split(/[ ,;]+/) : [];
      payload.conditions = JSON.parse(payload.conditions || '[]');
      await post('/api/sales/admin/policies', payload);
      window.location.reload();
    } catch (error) {
      if (status) status.textContent = error.message;
    }
  });

  policyAdminRoot.querySelectorAll('[data-policy-row]').forEach((row) => {
    const form = row.querySelector('[data-edit]');
    const status = row.querySelector('[data-status]');

    form?.addEventListener('submit', async (event) => {
      event.preventDefault();
      try {
        const payload = readForm(form);
        payload.enabled = payload.enabled === '1';
        payload.conditions = JSON.parse(payload.conditions || '[]');
        await post(`/api/sales/admin/policies/${encodeURIComponent(row.dataset.id)}`, payload);
        window.location.reload();
      } catch (error) {
        if (status) status.textContent = error.message;
      }
    });

    row.querySelector('[data-archive]')?.addEventListener('click', async () => {
      try {
        await post(`/api/sales/admin/policies/${encodeURIComponent(row.dataset.id)}/archive`, {
          configuration_version: Number(form?.elements.configuration_version?.value || 0),
        });
        window.location.reload();
      } catch (error) {
        if (status) status.textContent = error.message;
      }
    });
  });

  policyAdminRoot.querySelector('[data-preview]')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const output = policyAdminRoot.querySelector('[data-preview-result]');
    try {
      const result = await post('/api/sales/admin/policies/preview', readForm(event.currentTarget));
      if (output) {
        output.textContent = `Decision: ${result.decision}\nMatched policy: ${result.matched_policy_name || result.matched_policy || 'none'}\nReason: ${result.reason}\nExecuted: ${result.executed ? 'yes' : 'no'}`;
      }
    } catch (error) {
      if (output) output.textContent = error.message;
    }
  });
}
