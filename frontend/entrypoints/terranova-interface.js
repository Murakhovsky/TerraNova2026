import '../styles/design-system.css';
import '../styles/layouts/workspace.css';
import '../styles/workspace-shell.css';
import '../styles/copilot.css';
import { initProductionUX } from '../core/production.js';
import { initWorkspaceShell } from '../core/workspace-shell.js';
import { initInterfaceComponents } from '../components/interactive.js';
import '../features/copilot/copilot.js';

initProductionUX();
initWorkspaceShell();
initInterfaceComponents();
