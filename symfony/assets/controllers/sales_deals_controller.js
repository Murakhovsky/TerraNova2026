import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    rowAction(event) {
        const { actionId = '', id = '' } = event.detail || {};
        if (actionId !== 'sales.deal.view' || !id) return;

        window.location.assign(`/sales/deals/${encodeURIComponent(id)}`);
    }
}
