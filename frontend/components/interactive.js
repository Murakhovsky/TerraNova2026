export const initInterfaceComponents = () => {
  document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;

    const openTrigger = target.closest('[data-ui-dialog-open]');
    if (openTrigger instanceof HTMLElement) {
      const id = openTrigger.dataset.uiDialogOpen;
      const dialog = id ? document.getElementById(id) : null;
      if (dialog instanceof HTMLDialogElement) dialog.showModal();
      return;
    }

    const closeTrigger = target.closest('[data-ui-dialog-close]');
    if (closeTrigger instanceof HTMLElement) {
      const dialog = closeTrigger.closest('dialog');
      if (dialog instanceof HTMLDialogElement) dialog.close();
      return;
    }

    const dismissTrigger = target.closest('[data-ui-dismiss]');
    if (dismissTrigger instanceof HTMLElement) {
      dismissTrigger.closest('[data-ui-dismissible]')?.remove();
    }
  });
};
