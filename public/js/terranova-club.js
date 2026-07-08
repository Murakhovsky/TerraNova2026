(() => {
  const header = document.querySelector('[data-header]');
  const menuButton = document.querySelector('[data-menu-button]');

  if (header && menuButton) {
    menuButton.addEventListener('click', () => {
      const isOpen = header.classList.toggle('is-open');
      menuButton.setAttribute('aria-expanded', String(isOpen));
    });
  }

  document.querySelectorAll('[data-search-tab]').forEach((button) => {
    button.addEventListener('click', () => {
      document.querySelectorAll('[data-search-tab]').forEach((item) => item.classList.remove('is-active'));
      button.classList.add('is-active');

      const form = button.closest('form');
      const dealTypeInput = form ? form.querySelector('[data-search-deal-type]') : null;

      if (dealTypeInput && button.dataset.searchValue) {
        dealTypeInput.value = button.dataset.searchValue;
      }
    });
  });

  document.querySelectorAll('[data-category]').forEach((button) => {
    button.addEventListener('click', () => {
      document.querySelectorAll('[data-category]').forEach((item) => item.classList.remove('is-active'));
      button.classList.add('is-active');
    });
  });

  document.querySelectorAll('[data-toggle-text]').forEach((button) => {
    const initialText = button.textContent;
    const selectedText = button.dataset.toggleText || initialText;

    button.addEventListener('click', () => {
      const selected = button.classList.toggle('is-selected');
      button.textContent = selected ? selectedText : initialText;
    });
  });

  document.querySelectorAll('[data-lead-form]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      const status = form.querySelector('[data-form-status]');

      if (status) {
        status.textContent = 'Заявку підготовлено до передачі в EstateBook CRM, Telegram та n8n.';
      }
    });
  });
})();
