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

  document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target.closest('[data-save-property]') : null;

    if (target instanceof HTMLElement) {
      const button = target;
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
    }
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

  document.querySelectorAll('[data-inbound-request-form]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      const status = form.querySelector('[data-form-status]');

      if (status) {
        status.textContent = 'Заявку підготовлено до передачі в CRM, Telegram та n8n.';
      }
    });
  });

  const analyticsParams = new URLSearchParams(window.location.search);
  const storedCampaign = (() => {
    const campaign = {
      utm_source: analyticsParams.get('utm_source') || '',
      utm_medium: analyticsParams.get('utm_medium') || '',
      utm_campaign: analyticsParams.get('utm_campaign') || '',
    };

    if (campaign.utm_source || campaign.utm_medium || campaign.utm_campaign) {
      try {
        sessionStorage.setItem('tn_campaign', JSON.stringify(campaign));
      } catch (error) {
        return campaign;
      }
    }

    try {
      return JSON.parse(sessionStorage.getItem('tn_campaign') || 'null') || campaign;
    } catch (error) {
      return campaign;
    }
  })();

  const analyticsEventFor = (element) => {
    const explicit = element.getAttribute('data-analytics-event');
    if (explicit) {
      return explicit;
    }

    const href = element.getAttribute('href') || '';
    if (href.startsWith('tel:')) {
      return 'phone_click';
    }
    if (href.startsWith('viber:')) {
      return 'viber_click';
    }
    if (href.includes('t.me/') || href.startsWith('tg:')) {
      return 'telegram_click';
    }

    return '';
  };

  document.querySelectorAll('form[method="post"], form:not([method])').forEach((form) => {
    Object.entries(storedCampaign).forEach(([name, value]) => {
      if (!value || form.querySelector(`[name="${name}"]`)) {
        return;
      }

      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      input.value = value;
      form.appendChild(input);
    });
  });

  document.addEventListener('click', (event) => {
    const link = event.target instanceof Element ? event.target.closest('a, [data-analytics-event]') : null;
    if (!(link instanceof HTMLElement)) {
      return;
    }

    const eventType = analyticsEventFor(link);
    if (!eventType) {
      return;
    }

    const payload = new FormData();
    payload.set('event_type', eventType);
    payload.set('property_id', document.body.dataset.propertyId || '');
    payload.set('source_page', window.location.pathname + window.location.search);
    payload.set('target', link.getAttribute('href') || '');
    payload.set('label', (link.textContent || '').trim());
    payload.set('utm_source', storedCampaign.utm_source || '');
    payload.set('utm_medium', storedCampaign.utm_medium || '');
    payload.set('utm_campaign', storedCampaign.utm_campaign || '');

    if (navigator.sendBeacon) {
      navigator.sendBeacon('/analytics/track', payload);
      return;
    }

    fetch('/analytics/track', { method: 'POST', body: payload, credentials: 'same-origin', keepalive: true }).catch(() => {});
  });

  document.addEventListener('tn:content-updated', () => {
    syncSavedButtons();
    syncFavouriteList();
  });

  syncSavedButtons();
  syncFavouriteList();
})();
