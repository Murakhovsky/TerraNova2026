import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        topic: String,
        signalTarget: String,
        enabled: Boolean,
    };

    connect() {
        this.reconnectTimer = null;
        if (!this.enabledValue) {
            this.apply('unavailable');
            return;
        }
        this.apply(navigator.onLine ? 'live' : 'offline');
    }

    disconnect() {
        this.clearReconnectTimer();
    }

    online() {
        if (!this.enabledValue) return;
        this.apply('reconnecting');
        this.clearReconnectTimer();
        this.reconnectTimer = window.setTimeout(() => this.apply('live'), 1500);
    }

    offline() {
        if (!this.enabledValue) return;
        this.clearReconnectTimer();
        this.apply('offline');
    }

    stream(event) {
        if (!this.enabledValue) return;
        if (!navigator.onLine) {
            return;
        }

        if (
            this.hasSignalTargetValue
            && this.signalTargetValue !== ''
            && event.target?.getAttribute('target') !== this.signalTargetValue
        ) {
            return;
        }

        this.apply('live');
        window.requestAnimationFrame(() => {
            this.element.dispatchEvent(new CustomEvent('cos:realtime-update', {
                bubbles: true,
                detail: {
                    topic: this.hasTopicValue ? this.topicValue : '',
                },
            }));
        });
    }

    apply(state) {
        document.querySelectorAll('[data-cos-realtime-state]').forEach((node) => {
            node.dataset.state = state;
            const label = node.querySelector('[data-cos-realtime-state-label]');
            if (label) {
                label.textContent = state.charAt(0).toUpperCase() + state.slice(1);
            }
        });
    }

    clearReconnectTimer() {
        if (this.reconnectTimer !== null) {
            window.clearTimeout(this.reconnectTimer);
            this.reconnectTimer = null;
        }
    }
}
