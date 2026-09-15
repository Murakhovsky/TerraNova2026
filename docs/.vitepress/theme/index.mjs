import DefaultTheme from 'vitepress/theme';
import Layout from './Layout.vue';
import SystemStatus from './SystemStatus.vue';
import KnowledgeHealth from './KnowledgeHealth.vue';
import MermaidDiagram from './MermaidDiagram.vue';
import ProcessDiagram from './ProcessDiagram.vue';
import './custom.css';
import './mermaid.css';

export default {
  extends: DefaultTheme,
  Layout,
  enhanceApp({ app }) {
    app.component('SystemStatus', SystemStatus);
    app.component('KnowledgeHealth', KnowledgeHealth);
    app.component('MermaidDiagram', MermaidDiagram);
    app.component('ProcessDiagram', ProcessDiagram);
  },
};
