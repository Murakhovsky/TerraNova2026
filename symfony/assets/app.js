import './stimulus_bootstrap.js';
import './pwa_runtime.js';
import { initWebTelemetry } from './web_telemetry.js';
import '@hotwired/turbo';
import 'bootstrap';

document.documentElement.dataset.cosExperienceRuntime = 'assetmapper';

initWebTelemetry();
