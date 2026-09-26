import { Controller } from '@hotwired/stimulus';
import { bootMethodologyStudioBase } from '../islands/diagnostic_methodology/base.js';
import { bootMethodologyStudioV054 } from '../islands/diagnostic_methodology/v054.js';
import { bootMethodologyStudioV055 } from '../islands/diagnostic_methodology/v055.js';

export default class extends Controller {
    connect() {
        if (this.element.dataset.methodologyBooted === 'true') return;
        this.element.dataset.methodologyBooted = 'true';

        bootMethodologyStudioBase(this.element);
        bootMethodologyStudioV054(this.element);
        bootMethodologyStudioV055(this.element);
    }
}
