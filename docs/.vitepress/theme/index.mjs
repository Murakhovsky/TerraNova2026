import DefaultTheme from 'vitepress/theme';
import Layout from './Layout.vue';
import SystemStatus from './SystemStatus.vue';
import KnowledgeHealth from './KnowledgeHealth.vue';
import MermaidDiagram from './MermaidDiagram.vue';
import ProcessDiagram from './ProcessDiagram.vue';
import CrossDomainProcessTopology from './CrossDomainProcessTopology.vue';
import LocalizedPage from './LocalizedPage.vue';
import LocalizedHome from './LocalizedHome.vue';
import LocaleSwitcher from './LocaleSwitcher.vue';
import './custom.css';
import './mermaid.css';
import './readability.css';
import './localization.css';

export default {
  extends: DefaultTheme,
  Layout,
  enhanceApp({ app }) {
    app.component('SystemStatus', SystemStatus);
    app.component('KnowledgeHealth', KnowledgeHealth);
    app.component('MermaidDiagram', MermaidDiagram);
    app.component('ProcessDiagram', ProcessDiagram);
    app.component('CrossDomainProcessTopology', CrossDomainProcessTopology);
    app.component('LocalizedPage', LocalizedPage);
    app.component('LocalizedHome', LocalizedHome);
    app.component('LocaleSwitcher', LocaleSwitcher);
  },
};
