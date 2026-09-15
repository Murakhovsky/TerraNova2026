import '../styles/design-system.css';
import '../styles/layouts/public.css';
import '../features/public/surface.css';
import { initProductionUX } from '../core/production.js';
import { initPublicInteractions } from '../features/public/interactions.js';
import { initPublicSurface } from '../features/public/surface.js';

initProductionUX();
initPublicInteractions();
initPublicSurface();
