export const initInterfaceComponents = () => {
  document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;

    const drawerOpenTrigger = target.closest('[data-ui-drawer-open]');
    if (drawerOpenTrigger instanceof HTMLElement) {
      const id = drawerOpenTrigger.dataset.uiDrawerOpen;
      const drawer = id ? document.getElementById(id) : null;
      if (drawer instanceof HTMLDialogElement) drawer.showModal();
      return;
    }

    const drawerCloseTrigger = target.closest('[data-ui-drawer-close]');
    if (drawerCloseTrigger instanceof HTMLElement) {
      const drawer = drawerCloseTrigger.closest('[data-ui-drawer]');
      if (drawer instanceof HTMLDialogElement) drawer.close();
      return;
    }

    if (target instanceof HTMLDialogElement && target.matches('[data-ui-drawer]')) {
      const rect = target.getBoundingClientRect();
      const isPanelClick = event.clientX >= rect.left && event.clientX <= rect.right && event.clientY >= rect.top && event.clientY <= rect.bottom;
      if (!isPanelClick) target.close();
      return;
    }

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
