import '../styles/design-system.css';
import '../styles/layouts/workspace.css';
import { initInterfaceComponents } from '../components/interactive.js';
import { initWorkspaceShell } from '../core/workspace-shell.js';

const bootInterface = () => {
  initInterfaceComponents();
  initWorkspaceShell();
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', bootInterface, { once: true });
} else {
  bootInterface();
}
