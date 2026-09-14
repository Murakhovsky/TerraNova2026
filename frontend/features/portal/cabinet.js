const portalHeader = document.querySelector('[data-interface-surface="portal"]');

if (portalHeader && document.body) {
  const root = document.body;
  root.classList.add('tn-portal-cabinet');
  root.dataset.portalCabinet = 'true';

  root.querySelectorAll('.tn-cabinet-workspace form, .tn-property-form').forEach((form) => {
    form.addEventListener('submit', () => {
      if (form.getAttribute('aria-busy') === 'true') {
        return;
      }

      form.setAttribute('aria-busy', 'true');
      form.dataset.submitting = 'true';

      const submitter = form.querySelector('button[type="submit"], input[type="submit"]');
      if (submitter) {
        submitter.classList.add('is-pending');
      }
    });
  });
}
