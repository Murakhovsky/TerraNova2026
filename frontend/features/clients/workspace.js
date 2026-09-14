const workspace = document.querySelector('.tn-admin-page');

if (workspace) {
  workspace.classList.add('tn-client-workspace');
  workspace.setAttribute('data-client-workspace', '');

  workspace.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;

    form.dataset.submitting = 'true';
    const submitter = event.submitter instanceof HTMLElement
      ? event.submitter
      : form.querySelector('button[type="submit"], input[type="submit"]');

    if (submitter) {
      submitter.setAttribute('aria-busy', 'true');
      submitter.classList.add('is-pending');
    }
  });
}
