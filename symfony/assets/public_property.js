import { Application } from '@hotwired/stimulus';
import PublicPropertyController from './controllers/public_property_controller.js';
import PublicPropertyGalleryController from './controllers/public_property_gallery_controller.js';
import './pwa_runtime.js';
import { initWebTelemetry } from './web_telemetry.js';

const app = Application.start();
app.register('public-property', PublicPropertyController);
app.register('public-property-gallery', PublicPropertyGalleryController);

document.documentElement.dataset.cosExperienceRuntime = 'assetmapper-public-property';

initWebTelemetry();
