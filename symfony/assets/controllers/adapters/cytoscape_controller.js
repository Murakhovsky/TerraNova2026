import { Controller } from '@hotwired/stimulus';
import cytoscape from 'cytoscape';

export default class extends Controller {
    static values = {
        elements: Array,
        style: Array,
        layout: Object,
        options: Object,
    };

    connect() {
        this.cy = cytoscape({
            container: this.element,
            ...(this.hasOptionsValue ? this.optionsValue : {}),
            elements: this.hasElementsValue ? this.elementsValue : [],
            style: this.hasStyleValue ? this.styleValue : [],
            layout: this.hasLayoutValue ? this.layoutValue : { name: 'grid' },
        });
    }

    disconnect() {
        this.cy?.destroy();
        this.cy = null;
    }
}
