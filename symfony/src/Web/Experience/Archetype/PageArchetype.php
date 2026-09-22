<?php

declare(strict_types=1);

namespace App\Web\Experience\Archetype;

enum PageArchetype: string
{
    case ExecutiveDashboard = 'executive_dashboard';
    case DomainDashboard = 'domain_dashboard';
    case OperationalQueue = 'operational_queue';
    case Collection = 'collection';
    case EntityWorkspace = 'entity_workspace';
    case ProcessPipeline = 'process_pipeline';
    case FormEditor = 'form_editor';
    case MapSpatial = 'map_spatial';
    case SystemControlSurface = 'system_control_surface';
    case Portal = 'portal';
    case PublicCatalog = 'public_catalog';
    case PublicDetailMarketing = 'public_detail_marketing';
}
