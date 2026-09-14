import { initWorkspaceShell } from './workspace-shell-WEBV01.js';

const bootInterface = () => initWorkspaceShell();

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', bootInterface, { once: true });
} else {
  bootInterface();
}
