import DefaultTheme from 'vitepress/theme';
import Layout from './Layout.vue';
import SystemStatus from './SystemStatus.vue';
import './custom.css';

export default {
  extends: DefaultTheme,
  Layout,
  enhanceApp({ app }) {
    app.component('SystemStatus', SystemStatus);
  },
};
