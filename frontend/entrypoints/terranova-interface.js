import '../styles/interface.css';
import '../styles/workspace-mobile.css';
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
