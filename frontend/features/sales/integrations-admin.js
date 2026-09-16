const root = document.querySelector('[data-sales-integration-admin]');

if (root) {
  const csrf = root.dataset.csrf || '';

  const post = async (url, payload) => {
    const response = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrf,
      },
      body: JSON.stringify({ ...payload, csrf_token: csrf }),
    });
    const body = await response.json().catch(() => ({}));
    if (!response.ok || body.ok !== true) throw new Error(body.error || 'Request failed.');
    return body.data;
  };

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
      const data = Object.fromEntries(new FormData(form).entries());
      try {
        await post(`/api/sales/admin/integrations/${card.dataset.integrationId}/routes`, data);
        window.location.reload();
      } catch (error) {
        window.alert(error.message);
      }
    });
  });
}
