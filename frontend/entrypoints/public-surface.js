import '../features/public/surface.css';
import { initPublicSurface } from '../features/public/surface.js';

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initPublicSurface, { once: true });
} else {
  initPublicSurface();
}
