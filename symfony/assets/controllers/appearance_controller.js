import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['styleButton', 'densityButton'];

    static values = {
        theme: { type: String, default: 'light' },
        density: { type: String, default: 'comfortable' },
        style: { type: String, default: 'base' },
    };

    connect() {
        this.apply();
    }

    setTheme(event) {
        this.styleValue = 'base';
        this.themeValue = event.currentTarget.dataset.theme;
    }

    setStyle(event) {
        this.styleValue = event.currentTarget.dataset.style;
    }

    setDensity(event) {
        this.densityValue = event.currentTarget.dataset.density;
    }

    themeValueChanged() {
        this.apply();
    }

    styleValueChanged() {
        this.apply();
    }

    densityValueChanged() {
        this.apply();
    }

    apply() {
        const root = document.documentElement;
        const style = this.normalizeStyle(this.styleValue);

        root.dataset.cosDensity = this.normalizeDensity(this.densityValue);

        if (style === 'base') {
            root.dataset.cosTheme = this.normalizeTheme(this.themeValue);
            delete root.dataset.cosStyleLab;
        } else if (style === 'light' || style === 'dark') {
            root.dataset.cosTheme = style;
            delete root.dataset.cosStyleLab;
        } else {
            root.dataset.cosTheme = style === 'glass' ? 'dark' : 'light';
            root.dataset.cosStyleLab = style;
        }

        this.syncControls(style);
    }

    syncControls(style) {
        if (this.hasStyleButtonTarget) {
            this.styleButtonTargets.forEach((button) => {
                const active = button.dataset.style === style;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-pressed', String(active));
            });
        }

        if (this.hasDensityButtonTarget) {
            const density = this.normalizeDensity(this.densityValue);
            this.densityButtonTargets.forEach((button) => {
                const active = button.dataset.density === density;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-pressed', String(active));
            });
        }
    }

    normalizeStyle(style) {
        return ['base', 'light', 'dark', 'origin-a', 'origin-b', 'origin-c', 'glass'].includes(style)
            ? style
            : 'base';
    }

    normalizeTheme(theme) {
        return ['light', 'dark', 'system'].includes(theme) ? theme : 'system';
    }

    normalizeDensity(density) {
        return ['comfortable', 'compact'].includes(density) ? density : 'comfortable';
    }
}
