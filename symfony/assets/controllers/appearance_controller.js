import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        theme: { type: String, default: 'origin' },
        density: { type: String, default: 'comfortable' },
    };

    connect() {
        this.apply();
    }

    setTheme(event) {
        this.themeValue = event.currentTarget.dataset.theme;
    }

    setDensity(event) {
        this.densityValue = event.currentTarget.dataset.density;
    }

    themeValueChanged() {
        this.apply();
    }

    densityValueChanged() {
        this.apply();
    }

    apply() {
        const root = document.documentElement;
        root.dataset.cosTheme = this.normalizeTheme(this.themeValue);
        root.dataset.cosDensity = this.normalizeDensity(this.densityValue);
    }

    normalizeTheme(theme) {
        return ['light', 'dark', 'origin', 'system'].includes(theme) ? theme : 'origin';
    }

    normalizeDensity(density) {
        return ['comfortable', 'compact'].includes(density) ? density : 'comfortable';
    }
}
