import '../styles/interface.css';
import { initWorkspaceShell } from '../core/workspace-shell.js';

const bootInterface = () => {
  initWorkspaceShell();
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', bootInterface, { once: true });
} else {
  bootInterface();
}
