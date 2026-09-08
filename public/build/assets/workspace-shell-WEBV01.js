const STORAGE_KEY = 'tn_workspace_collapsed';

const setSidebarOpen = (open) => {
  const sidebar = document.querySelector('[data-workspace-sidebar]');
  const menu = document.querySelector('[data-workspace-menu]');

  if (!sidebar) return;
  sidebar.classList.toggle('is-mobile-open', open);
  menu?.setAttribute('aria-expanded', String(open));
};

const initCommandPalette = () => {
  const palette = document.querySelector('[data-workspace-command]');
  const trigger = document.querySelector('[data-workspace-command-trigger]');
  const input = palette?.querySelector('[data-workspace-command-input]');
  const empty = palette?.querySelector('[data-workspace-command-empty]');
  const items = [...(palette?.querySelectorAll('[data-command-item]') ?? [])];

  if (!palette || !trigger || !input) return;

  const close = () => {
    palette.hidden = true;
    document.body.classList.remove('tn-command-open');
    trigger.focus();
  };

  const open = () => {
    palette.hidden = false;
    document.body.classList.add('tn-command-open');
    input.value = '';
    items.forEach((item) => { item.hidden = false; });
    if (empty) empty.hidden = true;
    window.requestAnimationFrame(() => input.focus());
  };

  const filter = () => {
    const query = input.value.trim().toLocaleLowerCase('uk-UA');
    let visible = 0;

    items.forEach((item) => {
      const label = (item.dataset.commandLabel || item.textContent || '').toLocaleLowerCase('uk-UA');
      const match = !query || label.includes(query);
      item.hidden = !match;
      if (match) visible += 1;
    });

    if (empty) empty.hidden = visible !== 0;
  };

  trigger.addEventListener('click', open);
  palette.querySelectorAll('[data-workspace-command-close]').forEach((button) => button.addEventListener('click', close));
  input.addEventListener('input', filter);

  document.addEventListener('keydown', (event) => {
    if (event.key === '/' && !event.metaKey && !event.ctrlKey && !event.altKey && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)) {
      event.preventDefault();
      open();
    }

    if (event.key === 'Escape' && !palette.hidden) close();
  });
};

export const initWorkspaceShell = () => {
  const sidebar = document.querySelector('[data-workspace-sidebar]');
  if (!sidebar) return;

  document.body.classList.add('tn-has-workspace');

  const collapse = document.querySelector('[data-workspace-collapse]');
  const menu = document.querySelector('[data-workspace-menu]');
  const more = document.querySelector('[data-workspace-more]');

  let collapsed = false;
  try {
    collapsed = localStorage.getItem(STORAGE_KEY) === '1';
  } catch (error) {
    collapsed = false;
  }
  document.body.classList.toggle('tn-workspace-collapsed', collapsed);

  collapse?.addEventListener('click', () => {
    const next = !document.body.classList.contains('tn-workspace-collapsed');
    document.body.classList.toggle('tn-workspace-collapsed', next);
    collapse.setAttribute('aria-label', next ? 'Розгорнути навігацію' : 'Згорнути навігацію');
    collapse.textContent = next ? '›' : '‹';
    try {
      localStorage.setItem(STORAGE_KEY, next ? '1' : '0');
    } catch (error) {
      // Workspace remains fully usable when browser storage is unavailable.
    }
  });

  menu?.addEventListener('click', () => setSidebarOpen(!sidebar.classList.contains('is-mobile-open')));
  more?.addEventListener('click', () => setSidebarOpen(true));

  document.addEventListener('click', (event) => {
    if (window.innerWidth > 650 || !sidebar.classList.contains('is-mobile-open')) return;
    const target = event.target instanceof Element ? event.target : null;
    if (!target || sidebar.contains(target) || menu?.contains(target) || more?.contains(target)) return;
    setSidebarOpen(false);
  });

  initCommandPalette();
};
