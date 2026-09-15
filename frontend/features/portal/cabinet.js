const portalHeader = document.querySelector('[data-interface-surface="portal"][data-portal-header]');

if (portalHeader && document.body) {
  const root = document.body;
  const menuButton = portalHeader.querySelector('[data-portal-menu-button]');
  const menu = portalHeader.querySelector('[data-portal-menu]');

  root.classList.add('tn-portal-cabinet');
  root.dataset.portalCabinet = 'true';

  const closeMenu = () => {
    portalHeader.classList.remove('is-menu-open');
    if (menuButton) {
      menuButton.setAttribute('aria-expanded', 'false');
      menuButton.setAttribute('aria-label', 'Відкрити навігацію');
    }
  };

  if (menuButton && menu) {
    menuButton.addEventListener('click', () => {
      const willOpen = menuButton.getAttribute('aria-expanded') !== 'true';
      portalHeader.classList.toggle('is-menu-open', willOpen);
      menuButton.setAttribute('aria-expanded', String(willOpen));
      menuButton.setAttribute('aria-label', willOpen ? 'Закрити навігацію' : 'Відкрити навігацію');
    });

    menu.addEventListener('click', (event) => {
      if (event.target.closest('a')) closeMenu();
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') closeMenu();
    });
  }
}
