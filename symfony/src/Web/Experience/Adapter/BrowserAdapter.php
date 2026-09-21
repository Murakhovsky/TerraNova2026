<?php

declare(strict_types=1);

namespace App\Web\Experience\Adapter;

enum BrowserAdapter: string
{
    case Tabulator = 'tabulator';
    case FullCalendar = 'fullcalendar';
    case Sortable = 'sortable';
    case Flatpickr = 'flatpickr';
    case Cytoscape = 'cytoscape';

    public function stimulusController(): string
    {
        return match ($this) {
            self::Tabulator => 'adapters--tabulator',
            self::FullCalendar => 'adapters--calendar',
            self::Sortable => 'adapters--sortable',
            self::Flatpickr => 'adapters--flatpickr',
            self::Cytoscape => 'adapters--cytoscape',
        };
    }

    public function moduleSpecifier(): string
    {
        return match ($this) {
            self::Tabulator => 'tabulator-tables',
            self::FullCalendar => 'fullcalendar',
            self::Sortable => 'sortablejs',
            self::Flatpickr => 'flatpickr',
            self::Cytoscape => 'cytoscape',
        };
    }
}
