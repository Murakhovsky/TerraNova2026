import './pwa_runtime.js';
import { initWebTelemetry } from './web_telemetry.js';

document.documentElement.dataset.cosExperienceRuntime = 'assetmapper-public';

initWebTelemetry();
