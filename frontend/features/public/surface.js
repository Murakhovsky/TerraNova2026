export const initPublicSurface = () => {
  document.documentElement.dataset.publicSurface = 'ready';

  document.querySelectorAll('.tn-public-header[data-header]').forEach((header) => {
    const button = header.querySelector('[data-menu-button]');
    const close = () => {
      header.classList.remove('is-open');
      button?.setAttribute('aria-expanded', 'false');
      button?.setAttribute('aria-label', 'Відкрити навігацію');
    };

    if (button instanceof HTMLButtonElement) {
      button.addEventListener('click', () => {
        const willOpen = !header.classList.contains('is-open');
        header.classList.toggle('is-open', willOpen);
        button.setAttribute('aria-expanded', String(willOpen));
        button.setAttribute('aria-label', willOpen ? 'Закрити навігацію' : 'Відкрити навігацію');
      });
    }

    header.querySelectorAll('nav a').forEach((link) => link.addEventListener('click', close));

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') close();
    });
  });

  document.querySelectorAll('.tn-property-card img').forEach((image) => {
    if (!image.hasAttribute('loading')) image.setAttribute('loading', 'lazy');
    if (!image.hasAttribute('decoding')) image.setAttribute('decoding', 'async');
  });
};
