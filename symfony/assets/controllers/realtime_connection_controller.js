import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        topic: String,
    };

    connect() {
        this.reconnectTimer = null;
        this.apply(navigator.onLine ? 'live' : 'offline');
    }

    disconnect() {
        this.clearReconnectTimer();
    }

    online() {
        this.apply('reconnecting');
        this.clearReconnectTimer();
        this.reconnectTimer = window.setTimeout(() => this.apply('live'), 1500);
    }

    offline() {
        this.clearReconnectTimer();
        this.apply('offline');
    }

    stream() {
        if (!navigator.onLine) {
            return;
        }

        this.apply('live');
        this.element.dispatchEvent(new CustomEvent('cos:realtime-update', {
            bubbles: true,
            detail: {
                topic: this.hasTopicValue ? this.topicValue : '',
            },
        }));
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
