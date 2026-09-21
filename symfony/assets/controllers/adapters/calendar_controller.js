import { Controller } from '@hotwired/stimulus';
import { Calendar } from 'fullcalendar';
import dayGridPlugin from 'fullcalendar/daygrid';
import interactionPlugin from 'fullcalendar/interaction';

export default class extends Controller {
    static values = {
        events: Array,
        options: Object,
    };

    connect() {
        this.calendar = new Calendar(this.element, {
            plugins: [dayGridPlugin, interactionPlugin],
            initialView: 'dayGridMonth',
            ...(this.hasOptionsValue ? this.optionsValue : {}),
            events: this.hasEventsValue ? this.eventsValue : [],
        });
        this.calendar.render();
    }

    disconnect() {
        this.calendar?.destroy();
        this.calendar = null;
    }
}
