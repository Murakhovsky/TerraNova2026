import './stimulus_bootstrap.js';
import './pwa_runtime.js';
import './islands/methodology_studio.js';
import './islands/methodology_studio_v054.js';
import './islands/methodology_studio_v055.js';
import { initWebTelemetry } from './web_telemetry.js';
import '@hotwired/turbo';
import 'bootstrap';

document.documentElement.dataset.cosExperienceRuntime = 'assetmapper';

initWebTelemetry();
