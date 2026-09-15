import '../styles/design-system.css';
import '../styles/layouts/workspace.css';
import { initProductionUX } from '../core/production.js';
import { initInterfaceComponents } from '../components/interactive.js';
import { initWorkspaceShell } from '../core/workspace-shell.js';

const bootInterface = () => {
  initProductionUX();
  initInterfaceComponents();
  initWorkspaceShell();
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', bootInterface, { once: true });
} else {
  bootInterface();
}
