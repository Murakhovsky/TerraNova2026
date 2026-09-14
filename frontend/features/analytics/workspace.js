const root = document.body;

if (root) {
  root.classList.add('tn-analytics-workspace');
  root.dataset.analyticsWorkspace = 'true';

  root.querySelectorAll('.tn-analytics-workspace form').forEach((form) => {
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
