import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['button', 'status', 'intent', 'item', 'empty', 'count'];

    connect() {
        this.saved = new Set();
        this.load();
    }

    intent(event) {
        if (!this.hasIntentTarget) return;
        const intent = event.currentTarget.dataset.requestIntent || '';
        if (intent) this.intentTarget.value = intent;
    }

    async toggle(event) {
        const button = event.currentTarget;
        const publicId = button.dataset.publicId || '';
        if (!publicId || button.dataset.state === 'pending') return;

        button.dataset.state = 'pending';
        button.setAttribute('aria-busy', 'true');
        button.setAttribute('disabled', 'disabled');

        try {
            const body = new URLSearchParams({ public_id: publicId });
            const payload = await this.request('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body,
            });
            this.replace(payload.items || []);
            this.status('Вибране оновлено.');
        } catch {
            this.status('Не вдалося оновити вибране.');
        } finally {
            button.dataset.state = 'ready';
            button.removeAttribute('aria-busy');
            button.removeAttribute('disabled');
        }
    }

    async load() {
        try {
            const payload = await this.request();
            this.replace(payload.items || []);
        } catch {
            this.status('Стан вибраного тимчасово недоступний.');
        }
    }

    async request(suffix = '', options = {}) {
        const response = await fetch('/api/v1/public/properties/favourites' + suffix, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', ...(options.headers || {}) },
            ...options,
        });
        const payload = await response.json().catch(() => null);

        if (!response.ok || !payload?.ok || !Array.isArray(payload?.data?.items)) {
            throw new Error('Favourites state is unavailable.');
        }

        return payload.data;
    }

    replace(items) {
        this.saved = new Set(items.filter((id) => typeof id === 'string' && id));
        this.buttonTargets.forEach((button) => {
            const publicId = button.dataset.publicId || '';
            const selected = this.saved.has(publicId);
            button.setAttribute('aria-pressed', String(selected));
            button.textContent = selected ? 'У вибраному' : '♡';
        });

        let visible = 0;
        this.itemTargets.forEach((item) => {
            const publicId = item.dataset.publicId || '';
            const selected = this.saved.has(publicId);
            item.hidden = !selected;
            if (selected) visible += 1;
        });

        if (this.hasCountTarget) {
            this.countTarget.textContent = String(visible);
        }
        if (this.hasEmptyTarget) {
            this.emptyTarget.hidden = visible > 0;
        }
    }

    status(message) {
        if (!this.hasStatusTarget) return;
        this.statusTarget.textContent = message;
    }
}
