import '../styles/design-system.css';
import '../styles/layouts/public.css';
import '../features/public/surface.css';
import { initPublicSurface } from '../features/public/surface.js';
import { initPublicInteractions } from '../features/public/interactions.js';

const bootPublicSurface = () => {
  initPublicSurface();
  initPublicInteractions();
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', bootPublicSurface, { once: true });
} else {
  bootPublicSurface();
}
