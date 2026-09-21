import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [
        'sidebar',
        'menuButton',
        'palette',
        'paletteInput',
        'searchFrame',
        'resultItem',
        'activityPanel',
        'activityFrame',
        'activityButton',
        'notificationButton',
        'activityCounter',
        'notificationCounter',
        'aiPanel',
        'aiFrame',
        'aiButton',
    ];

    static values = {
        searchUrl: String,
        activityUrl: String,
        aiUrl: String,
    };

    connect() {
        this.boundKeydown = this.onKeydown.bind(this);
        this.searchTimer = null;
        this.activeResultIndex = -1;
        document.addEventListener('keydown', this.boundKeydown);
    }

    disconnect() {
        document.removeEventListener('keydown', this.boundKeydown);

        if (this.searchTimer !== null) {
            window.clearTimeout(this.searchTimer);
        }
    }

    toggleSidebar() {
        const open = !this.sidebarTarget.classList.contains('is-mobile-open');
        this.setSidebarOpen(open);
    }

    openSidebar() {
        this.setSidebarOpen(true);
    }

    closeSidebar() {
        this.setSidebarOpen(false);
    }

    setSidebarOpen(open) {
        this.sidebarTarget.classList.toggle('is-mobile-open', open);

        if (this.hasMenuButtonTarget) {
            this.menuButtonTarget.setAttribute('aria-expanded', String(open));
        }
    }


    openAI() {
        if (!this.hasAiPanelTarget || !this.hasAiFrameTarget || !this.hasAiUrlValue) {
            return;
        }

        const url = new URL(this.aiUrlValue, window.location.origin);
        const workspace = this.element.querySelector('[data-workspace-id]');
        if (workspace) {
            const workspaceId = workspace.dataset.workspaceId || '';
            const entityKey = workspace.dataset.workspacePlatformEntityKeyValue || '';
            if (workspaceId !== '') {
                url.searchParams.set('workspace', workspaceId);
            }
            if (entityKey !== '') {
                url.searchParams.set('entity', entityKey);
            }
        }

        this.aiPanelTarget.hidden = false;
        document.body.dataset.cosAiOpen = 'true';
        this.aiFrameTarget.setAttribute('src', url.toString());

        if (this.hasAiButtonTarget) {
            this.aiButtonTarget.setAttribute('aria-expanded', 'true');
        }
    }

    closeAI() {
        if (!this.hasAiPanelTarget) {
            return;
        }

        this.aiPanelTarget.hidden = true;
        delete document.body.dataset.cosAiOpen;

        if (this.hasAiButtonTarget) {
            this.aiButtonTarget.setAttribute('aria-expanded', 'false');
        }
    }

    aiLoaded() {
        if (!this.hasAiFrameTarget) {
            return;
        }

        this.aiFrameTarget.removeAttribute('aria-busy');
    }

    openActivityCenter() {
        this.openActivitySurface('activity');
    }

    openNotifications() {
        this.openActivitySurface('notifications');
    }

    openActivitySurface(tab) {
        if (!this.hasActivityPanelTarget || !this.hasActivityFrameTarget || !this.hasActivityUrlValue) {
            return;
        }

        const url = new URL(this.activityUrlValue, window.location.origin);
        url.searchParams.set('tab', tab);

        this.activityPanelTarget.hidden = false;
        document.body.dataset.cosActivityCenterOpen = 'true';
        this.activityFrameTarget.setAttribute('src', url.toString());

        if (this.hasActivityButtonTarget) {
            this.activityButtonTarget.setAttribute('aria-expanded', String(tab === 'activity'));
        }
        if (this.hasNotificationButtonTarget) {
            this.notificationButtonTarget.setAttribute('aria-expanded', String(tab === 'notifications'));
        }
    }

    closeActivityCenter() {
        if (!this.hasActivityPanelTarget) {
            return;
        }

        this.activityPanelTarget.hidden = true;
        delete document.body.dataset.cosActivityCenterOpen;

        if (this.hasActivityButtonTarget) {
            this.activityButtonTarget.setAttribute('aria-expanded', 'false');
        }
        if (this.hasNotificationButtonTarget) {
            this.notificationButtonTarget.setAttribute('aria-expanded', 'false');
        }
    }

    activityCenterLoaded() {
        if (!this.hasActivityFrameTarget) {
            return;
        }

        const meta = this.activityFrameTarget.querySelector('[data-activity-center-meta]');
        if (!meta) {
            return;
        }

        this.updateCounter(
            this.hasActivityCounterTarget ? this.activityCounterTarget : null,
            Number.parseInt(meta.dataset.activityCount || '0', 10),
        );
        this.updateCounter(
            this.hasNotificationCounterTarget ? this.notificationCounterTarget : null,
            Number.parseInt(meta.dataset.notificationCount || '0', 10),
        );
    }

    refreshActivityCenter() {
        if (!this.hasActivityFrameTarget) {
            return;
        }

        const src = this.activityFrameTarget.getAttribute('src');
        if (src) {
            this.activityFrameTarget.setAttribute('src', src);
        }
    }

    updateCounter(target, count) {
        if (!target) {
            return;
        }

        const normalized = Number.isFinite(count) && count > 0 ? count : 0;
        target.hidden = normalized === 0;
        target.textContent = normalized > 99 ? '99+' : String(normalized);
    }

    openPalette() {
        this.paletteTarget.hidden = false;
        document.body.dataset.cosCommandOpen = 'true';
        this.paletteInputTarget.value = '';
        this.activeResultIndex = -1;
        this.loadSearch('');

        window.requestAnimationFrame(() => this.paletteInputTarget.focus());
    }

    closePalette() {
        this.paletteTarget.hidden = true;
        delete document.body.dataset.cosCommandOpen;
        this.activeResultIndex = -1;
        this.clearResultSelection();
    }

    searchPalette() {
        if (this.searchTimer !== null) {
            window.clearTimeout(this.searchTimer);
        }

        const query = this.paletteInputTarget.value.trim();
        this.searchTimer = window.setTimeout(() => {
            this.loadSearch(query);
        }, 160);
    }

    loadSearch(query) {
        if (!this.hasSearchFrameTarget || !this.hasSearchUrlValue) {
            return;
        }

        const url = new URL(this.searchUrlValue, window.location.origin);
        if (query !== '') {
            url.searchParams.set('q', query);
        }

        this.searchFrameTarget.setAttribute('aria-busy', 'true');
        this.searchFrameTarget.setAttribute('src', url.toString());
    }

    searchLoaded() {
        if (this.hasSearchFrameTarget) {
            this.searchFrameTarget.setAttribute('aria-busy', 'false');
        }

        this.activeResultIndex = -1;
        this.clearResultSelection();
    }

    moveSelection(delta) {
        const items = this.resultItemTargets;
        if (items.length === 0) {
            return;
        }

        this.activeResultIndex += delta;
        if (this.activeResultIndex < 0) {
            this.activeResultIndex = items.length - 1;
        }
        if (this.activeResultIndex >= items.length) {
            this.activeResultIndex = 0;
        }

        items.forEach((item, index) => {
            const active = index === this.activeResultIndex;
            item.classList.toggle('is-selected', active);
            item.setAttribute('aria-selected', String(active));

            if (active) {
                item.scrollIntoView({ block: 'nearest' });
            }
        });
    }

    activateSelection() {
        const item = this.resultItemTargets[this.activeResultIndex];
        if (!item) {
            return false;
        }

        item.click();
        return true;
    }

    clearResultSelection() {
        this.resultItemTargets.forEach((item) => {
            item.classList.remove('is-selected');
            item.setAttribute('aria-selected', 'false');
        });
    }

    onKeydown(event) {
        const tagName = document.activeElement?.tagName;
        const editing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(tagName);
        const paletteOpen = !this.paletteTarget.hidden;

        if (
            (event.key === '/' && !editing && !event.metaKey && !event.ctrlKey && !event.altKey)
            || ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k')
        ) {
            event.preventDefault();
            this.openPalette();
            return;
        }

        if (paletteOpen && event.key === 'ArrowDown') {
            event.preventDefault();
            this.moveSelection(1);
            return;
        }

        if (paletteOpen && event.key === 'ArrowUp') {
            event.preventDefault();
            this.moveSelection(-1);
            return;
        }

        if (paletteOpen && event.key === 'Enter' && this.activeResultIndex >= 0) {
            event.preventDefault();
            this.activateSelection();
            return;
        }

        if (event.key === 'Escape') {
            if (paletteOpen) {
                this.closePalette();
            }

            if (this.hasActivityPanelTarget && !this.activityPanelTarget.hidden) {
                this.closeActivityCenter();
            }

            if (this.hasAiPanelTarget && !this.aiPanelTarget.hidden) {
                this.closeAI();
            }

            if (this.sidebarTarget.classList.contains('is-mobile-open')) {
                this.closeSidebar();
            }
        }
    }
}
