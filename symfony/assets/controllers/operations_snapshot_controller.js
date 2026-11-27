import { Controller } from '@hotwired/stimulus';

// Mercure is only an invalidation signal. The authoritative snapshot is SSR.
export default class extends Controller {
    static targets = ['frame', 'state'];

    connect() {
        this.pending = false;
        this.signalSeen = false;
        this.dirty = false;
        this.timer = window.setInterval(() => this.refresh(false), 30000);
        this.setState(navigator.onLine ? 'stale' : 'offline');
    }

    disconnect() {
        window.clearInterval(this.timer);
        window.clearTimeout(this.watchdog);
    }

    signal() {
        if (!navigator.onLine) { this.offline(); return; }
        this.dirty = true;
        this.setState(navigator.onLine ? 'stale' : 'offline');
        this.refresh(true);
    }
    online() {
        this.setState('reconnecting');
        this.refresh(false);
    }
    offline() {
        this.signalSeen = false;
        this.dirty = false;
        this.setState('offline');
    }

    refresh(signal) {
        if (!navigator.onLine) { this.offline(); return; }
        // Never replace a focused form or an in-progress human decision.
        if (this.pending || this.frameTarget.contains(document.activeElement) && document.activeElement?.closest('form')) return;
        this.pending = true;
        this.signalSeen = signal || this.dirty;
        this.dirty = false;
        this.watchdog = window.setTimeout(() => this.failed(), 15000);
        const url = new URL(window.location.href);
        if (this.frameTarget.src === url.href) this.frameTarget.reload();
        else this.frameTarget.src = url.href;
    }

    loaded(event) {
        if (event.target !== this.frameTarget) return;
        window.clearTimeout(this.watchdog);
        if (!this.pending) return;
        this.pending = false;
        const content = this.frameTarget.querySelector('[data-operations-snapshot-content]');
        const complete = content?.dataset.partial === 'false';
        this.setState(!navigator.onLine ? 'offline' : !complete || this.dirty || !this.signalSeen ? 'stale' : 'live');
        // A signal during an in-flight read may correspond to a newer durable write.
        if (this.dirty) this.refresh(true);
    }

    failed(event) {
        if (event && event.target !== this.frameTarget) return;
        window.clearTimeout(this.watchdog);
        this.pending = false;
        this.signalSeen = false;
        this.setState(navigator.onLine ? 'stale' : 'offline');
    }

    setState(state) {
        this.stateTarget.dataset.connection = state;
        this.stateTarget.textContent = {
            live: 'Live · отримано сигнал і звірено знімок',
            reconnecting: 'Відновлення зв’язку · перевіряємо знімок',
            offline: 'Offline · показано останній знімок',
            stale: 'Знімок даних · realtime не підтверджено',
        }[state];
    }
}
