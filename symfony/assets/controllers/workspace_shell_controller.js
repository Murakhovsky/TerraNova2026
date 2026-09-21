import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [
        'sidebar',
        'menuButton',
        'palette',
        'paletteInput',
        'commandItem',
        'empty',
    ];

    connect() {
        this.boundKeydown = this.onKeydown.bind(this);
        document.addEventListener('keydown', this.boundKeydown);
    }

    disconnect() {
        document.removeEventListener('keydown', this.boundKeydown);
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

    openPalette() {
        this.paletteTarget.hidden = false;
        document.body.dataset.cosCommandOpen = 'true';
        this.paletteInputTarget.value = '';
        this.commandItemTargets.forEach((item) => {
            item.hidden = false;
        });
        this.emptyTarget.hidden = true;

        window.requestAnimationFrame(() => this.paletteInputTarget.focus());
    }

    closePalette() {
        this.paletteTarget.hidden = true;
        delete document.body.dataset.cosCommandOpen;
    }

    filterPalette() {
        const query = this.paletteInputTarget.value.trim().toLocaleLowerCase();
        let visible = 0;

        this.commandItemTargets.forEach((item) => {
            const haystack = [
                item.dataset.commandLabel || '',
                item.dataset.commandKind || '',
            ].join(' ').toLocaleLowerCase();

            const match = query === '' || haystack.includes(query);
            item.hidden = !match;

            if (match) {
                visible += 1;
            }
        });

        this.emptyTarget.hidden = visible !== 0;
    }

    onKeydown(event) {
        const tagName = document.activeElement?.tagName;
        const editing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(tagName);

        if (
            (event.key === '/' && !editing && !event.metaKey && !event.ctrlKey && !event.altKey)
            || ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k')
        ) {
            event.preventDefault();
            this.openPalette();
            return;
        }

        if (event.key === 'Escape') {
            if (!this.paletteTarget.hidden) {
                this.closePalette();
            }

            if (this.sidebarTarget.classList.contains('is-mobile-open')) {
                this.closeSidebar();
            }
        }
    }
}
