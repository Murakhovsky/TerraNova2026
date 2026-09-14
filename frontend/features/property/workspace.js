const root = document.body;

if (root) {
  root.classList.add('tn-property-workspace');
  root.dataset.propertyWorkspace = 'true';

  root.querySelectorAll('form').forEach((form) => {
    form.addEventListener('submit', () => {
      if (form.getAttribute('aria-busy') === 'true') {
        return;
      }

      form.setAttribute('aria-busy', 'true');
      form.dataset.submitting = 'true';
    });
  });
}
