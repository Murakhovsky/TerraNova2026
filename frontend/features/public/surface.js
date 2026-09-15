export const initPublicSurface = () => {
  document.documentElement.dataset.publicSurface = 'ready';

  document.querySelectorAll('.tn-public-header nav a').forEach((link) => {
    link.addEventListener('click', () => {
      const header = link.closest('.tn-public-header');
      const button = header?.querySelector('[data-menu-button]');
      header?.classList.remove('is-open');
      button?.setAttribute('aria-expanded', 'false');
    });
  });

  document.querySelectorAll('.tn-property-card img').forEach((image) => {
    if (!image.hasAttribute('loading')) image.setAttribute('loading', 'lazy');
    if (!image.hasAttribute('decoding')) image.setAttribute('decoding', 'async');
  });

  document.querySelectorAll('.tn-inbound-request-form, .tn-object-form, .tn-submit-form').forEach((form) => {
    form.addEventListener('submit', () => {
      form.setAttribute('aria-busy', 'true');
      const submit = form.querySelector('button[type="submit"], input[type="submit"]');
      if (submit instanceof HTMLButtonElement || submit instanceof HTMLInputElement) {
        submit.disabled = true;
      }
    });
  });
};
