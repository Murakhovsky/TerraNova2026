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

  const savedKey = 'tn_saved_properties';
  const readSaved = () => {
    try {
      return JSON.parse(localStorage.getItem(savedKey) || '[]');
    } catch (error) {
      return [];
    }
  };
  const writeSaved = (items) => localStorage.setItem(savedKey, JSON.stringify([...new Set(items)]));
  const savedItems = new Set(readSaved());

  const syncSavedButtons = () => {
    document.querySelectorAll('[data-save-property]').forEach((button) => {
      const id = button.dataset.saveProperty;
      const initialText = button.dataset.initialText || button.textContent;
      const selectedText = button.dataset.toggleText || initialText;

      button.dataset.initialText = initialText;
      button.classList.toggle('is-selected', savedItems.has(id));
      button.textContent = savedItems.has(id) ? selectedText : initialText;
    });
  };

  const syncFavouriteList = () => {
    const items = document.querySelectorAll('[data-favourite-item]');
    const empty = document.querySelector('[data-favourite-empty]');
    const count = document.querySelector('[data-favourite-count]');
    let visible = 0;

    items.forEach((item) => {
      const selected = savedItems.has(item.dataset.favouriteItem);
      item.hidden = !selected;

      if (selected) {
        visible += 1;
      }
    });

    if (empty) {
      empty.hidden = visible > 0;
    }

    if (count) {
      count.textContent = String(visible);
    }
  };

  document.querySelectorAll('[data-save-property]').forEach((button) => {
    button.addEventListener('click', () => {
      const id = button.dataset.saveProperty;

      if (!id) {
        return;
      }

      if (savedItems.has(id)) {
        savedItems.delete(id);
      } else {
        savedItems.add(id);
      }

      writeSaved([...savedItems]);
      syncSavedButtons();
      syncFavouriteList();
    });
  });

  document.querySelectorAll('[data-toggle-text]:not([data-save-property])').forEach((button) => {
    const initialText = button.textContent;
    const selectedText = button.dataset.toggleText || initialText;

    button.addEventListener('click', () => {
      const selected = button.classList.toggle('is-selected');
      button.textContent = selected ? selectedText : initialText;
    });
  });

  document.querySelectorAll('[data-property-gallery]').forEach((gallery) => {
    const mainImage = gallery.querySelector('[data-gallery-main]');
    const openLink = gallery.querySelector('[data-gallery-open]');
    const thumbs = gallery.querySelectorAll('[data-gallery-thumb]');

    thumbs.forEach((button) => {
      button.addEventListener('click', () => {
        const image = button.dataset.image;
        const alt = button.dataset.alt || '';

        if (!image || !mainImage) {
          return;
        }

        mainImage.src = image;
        mainImage.alt = alt;

        if (openLink) {
          openLink.href = image;
        }

        thumbs.forEach((item) => item.classList.remove('is-active'));
        button.classList.add('is-active');
      });
    });
  });

  syncSavedButtons();
  syncFavouriteList();

  document.querySelectorAll('[data-inbound-request-form]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      const status = form.querySelector('[data-form-status]');

      if (status) {
        status.textContent = 'Заявку підготовлено до передачі в CRM, Telegram та n8n.';
      }
    });
  });
})();
