(() => {
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

})();
