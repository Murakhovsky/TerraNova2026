import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { csrf: String };

    create(event) {
        event.preventDefault();
        this.submit('/api/v1/sales/admin/teams', 'POST', Object.fromEntries(new FormData(event.currentTarget).entries()), event.currentTarget);
    }

    membership(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const card = form.closest('[data-user-id]');
        const data = Object.fromEntries(new FormData(form).entries());
        const teamId = data.team_id || '';
        if (!card || !teamId) return;
        delete data.team_id;
        data.assignment_enabled = form.querySelector('[name="assignment_enabled"]')?.checked === true;
        data.approval_enabled = form.querySelector('[name="approval_enabled"]')?.checked === true;
        this.submit(`/api/v1/sales/admin/teams/${encodeURIComponent(teamId)}/members/${encodeURIComponent(card.dataset.userId)}`, 'PUT', data, card);
    }

    capabilities(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const card = form.closest('[data-user-id]');
        if (!card) return;
        const capabilities = [...form.querySelectorAll('input[name="capabilities[]"]:checked')].map((input) => input.value);
        this.submit(`/api/v1/sales/admin/users/${encodeURIComponent(card.dataset.userId)}/capabilities`, 'PUT', { capabilities }, card, false);
    }

    async submit(url, method, data, root, reload = true) {
        const status = root.querySelector?.('[data-user-status]');
        if (status) status.textContent = 'Saving…';
        try {
            const response = await fetch(url, {
                method,
                credentials: 'same-origin',
                headers: { Accept:'application/json', 'Content-Type':'application/json', 'X-CSRF-Token':this.csrfValue || '' },
                body: JSON.stringify(data || {}),
            });
            const body = await response.json().catch(() => ({}));
            if (!response.ok || body.ok !== true) throw new Error(body.message || body.error || 'Request failed.');
            if (status) status.textContent = 'Saved.';
            if (reload) window.location.reload();
        } catch (error) {
            if (status) status.textContent = error.message || 'Request failed.';
        }
    }
}
