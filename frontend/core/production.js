const submitControls = (form) => form.querySelectorAll('button[type="submit"], input[type="submit"]');

const resetFormState = (form) => {
  form.removeAttribute('aria-busy');
  delete form.dataset.submitting;
  submitControls(form).forEach((control) => {
    control.disabled = false;
    control.classList.remove('is-pending');
  });
};

export const initProductionUX = () => {
  if (document.documentElement.dataset.productionUx === 'ready') return;
  document.documentElement.dataset.productionUx = 'ready';

  document.addEventListener('submit', (event) => {
    const form = event.target instanceof HTMLFormElement ? event.target : null;
    if (!form || form.method.toLowerCase() === 'get' || form.dataset.allowRepeatSubmit === 'true') return;

    if (form.dataset.submitting === 'true') {
      event.preventDefault();
      return;
    }

    form.dataset.submitting = 'true';
    form.setAttribute('aria-busy', 'true');
    submitControls(form).forEach((control) => {
      control.disabled = true;
      control.classList.add('is-pending');
    });
  });

  window.addEventListener('pageshow', () => {
    document.querySelectorAll('form[data-submitting="true"]').forEach(resetFormState);
  });
};
