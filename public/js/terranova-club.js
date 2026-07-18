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

  document.querySelectorAll('[data-request-intent]').forEach((trigger) => {
    trigger.addEventListener('click', () => {
      const intent = trigger.getAttribute('data-request-intent');
      const input = document.querySelector('[data-request-intent-input]');

      if (intent && input) {
        input.value = intent;
      }
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

  const renderDropzonePreview = (zone, files) => {
    const preview = zone.querySelector('[data-dropzone-preview]');
    if (!preview) {
      return;
    }

    (zone._tnObjectUrls || []).forEach((url) => URL.revokeObjectURL(url));
    zone._tnObjectUrls = [];
    preview.replaceChildren();

    if (!files.length) {
      return;
    }

    files.slice(0, 8).forEach((file) => {
      const item = document.createElement('span');
      item.className = 'tn-dropzone__file';

      if (file.type && file.type.startsWith('image/')) {
        const image = document.createElement('img');
        const url = URL.createObjectURL(file);
        zone._tnObjectUrls.push(url);
        image.src = url;
        image.alt = '';
        item.appendChild(image);
      }

      const name = document.createElement('small');
      name.textContent = file.name;
      item.appendChild(name);
      preview.appendChild(item);
    });

    if (files.length > 8) {
      const more = document.createElement('span');
      more.className = 'tn-dropzone__file';
      more.textContent = `+${files.length - 8}`;
      preview.appendChild(more);
    }
  };

  document.querySelectorAll('[data-dropzone]').forEach((zone) => {
    const input = zone.querySelector('[data-dropzone-input]');

    if (!input) {
      return;
    }

    const assignFiles = (files) => {
      const items = [...files].filter((file) => file.type.startsWith('image/'));
      const selected = input.multiple ? items : items.slice(0, 1);

      if (selected.length && typeof DataTransfer !== 'undefined') {
        const transfer = new DataTransfer();
        selected.forEach((file) => transfer.items.add(file));
        input.files = transfer.files;
      }

      renderDropzonePreview(zone, [...input.files]);
    };

    input.addEventListener('change', () => renderDropzonePreview(zone, [...input.files]));

    ['dragenter', 'dragover'].forEach((eventName) => {
      zone.addEventListener(eventName, (event) => {
        event.preventDefault();
        zone.classList.add('is-dragover');
      });
    });

    ['dragleave', 'drop'].forEach((eventName) => {
      zone.addEventListener(eventName, () => {
        zone.classList.remove('is-dragover');
      });
    });

    zone.addEventListener('drop', (event) => {
      event.preventDefault();
      assignFiles(event.dataTransfer ? event.dataTransfer.files : []);
    });
  });

  document.querySelectorAll('[data-media-grid]').forEach((grid) => {
    let draggedCard = null;

    const syncMediaOrder = () => {
      grid.querySelectorAll('[data-media-card]').forEach((card, index) => {
        const input = card.querySelector('[data-media-sort-input]');
        if (input) {
          input.value = String((index + 1) * 10);
        }
      });
    };

    const cardAfterPointer = (pointerY) => {
      const cards = [...grid.querySelectorAll('[data-media-card]:not(.is-dragging)')];
      return cards.reduce((closest, card) => {
        const box = card.getBoundingClientRect();
        const offset = pointerY - box.top - box.height / 2;

        if (offset < 0 && offset > closest.offset) {
          return { offset, card };
        }

        return closest;
      }, { offset: Number.NEGATIVE_INFINITY, card: null }).card;
    };

    grid.querySelectorAll('[data-media-card]').forEach((card) => {
      card.addEventListener('dragstart', (event) => {
        if (event.target instanceof Element && event.target.closest('.tn-media-card__controls')) {
          event.preventDefault();
          return;
        }

        draggedCard = card;
        card.classList.add('is-dragging');
      });

      card.addEventListener('dragend', () => {
        card.classList.remove('is-dragging');
        draggedCard = null;
        syncMediaOrder();
      });
    });

    grid.addEventListener('dragover', (event) => {
      event.preventDefault();

      if (!draggedCard) {
        return;
      }

      const nextCard = cardAfterPointer(event.clientY);
      if (nextCard) {
        grid.insertBefore(draggedCard, nextCard);
      } else {
        grid.appendChild(draggedCard);
      }
    });

    syncMediaOrder();
  });

  document.querySelectorAll('[data-media-delete-checkbox]').forEach((checkbox) => {
    checkbox.addEventListener('change', () => {
      const card = checkbox.closest('[data-media-card]');
      if (card) {
        card.classList.toggle('is-marked-delete', checkbox.checked);
      }
    });
  });

  document.querySelectorAll('[data-media-form]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      const deleteCount = form.querySelectorAll('[data-media-delete-checkbox]:checked').length;

      if (deleteCount > 0 && !confirm(`Видалити позначені фото: ${deleteCount}?`)) {
        event.preventDefault();
        return;
      }

      form.querySelectorAll('[data-media-grid]').forEach((grid) => {
        grid.querySelectorAll('[data-media-card]').forEach((card, index) => {
          const input = card.querySelector('[data-media-sort-input]');
          if (input) {
            input.value = String((index + 1) * 10);
          }
        });
      });

      form.querySelectorAll('[data-media-submit]').forEach((submit) => {
        submit.disabled = true;
        submit.textContent = 'Зберігаю...';
      });
    });
  });

  document.querySelectorAll('[data-copy-value]').forEach((button) => {
    const originalText = button.textContent;

    button.addEventListener('click', async () => {
      const value = button.getAttribute('data-copy-value') || '';
      if (!value) {
        return;
      }

      try {
        await navigator.clipboard.writeText(value);
        button.textContent = 'Скопійовано';
      } catch (error) {
        const helper = document.createElement('textarea');
        helper.value = value;
        helper.setAttribute('readonly', 'readonly');
        helper.style.position = 'fixed';
        helper.style.left = '-9999px';
        document.body.appendChild(helper);
        helper.select();
        document.execCommand('copy');
        helper.remove();
        button.textContent = 'Скопійовано';
      }

      window.setTimeout(() => {
        button.textContent = originalText;
      }, 1800);
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
