const analyticsEventFor = (element) => {
  const explicit = element.getAttribute('data-analytics-event');
  if (explicit) return explicit;

  const href = element.getAttribute('href') || '';
  if (href.startsWith('tel:')) return 'phone_click';
  if (href.startsWith('viber:')) return 'viber_click';
  if (href.includes('t.me/') || href.startsWith('tg:')) return 'telegram_click';
  return '';
};

const campaignFromLocation = () => {
  const params = new URLSearchParams(window.location.search);
  return {
    utm_source: params.get('utm_source') || '',
    utm_medium: params.get('utm_medium') || '',
    utm_campaign: params.get('utm_campaign') || '',
  };
};

const fetchFavourites = async (options = {}) => {
  const response = await fetch('/api/v1/public/properties/favourites', {
    credentials: 'same-origin',
    headers: { Accept: 'application/json', ...(options.headers || {}) },
    ...options,
  });
  const payload = await response.json().catch(() => null);
  if (!response.ok || !payload?.ok || !Array.isArray(payload?.data?.items)) {
    throw new Error('Favourites state is unavailable.');
  }
  return payload.data;
};

export const initPublicInteractions = () => {
  if (document.documentElement.dataset.publicInteractions === 'ready') return;
  document.documentElement.dataset.publicInteractions = 'ready';

  document.querySelectorAll('[data-search-tab]').forEach((button) => {
    button.addEventListener('click', () => {
      document.querySelectorAll('[data-search-tab]').forEach((item) => item.classList.remove('is-active'));
      button.classList.add('is-active');

      const form = button.closest('form');
      const dealTypeInput = form?.querySelector('[data-search-deal-type]');
      if (dealTypeInput && button.dataset.searchValue) dealTypeInput.value = button.dataset.searchValue;
    });
  });

  document.querySelectorAll('[data-category]').forEach((button) => {
    button.addEventListener('click', () => {
      document.querySelectorAll('[data-category]').forEach((item) => item.classList.remove('is-active'));
      button.classList.add('is-active');
    });
  });

  const savedItems = new Set();

  const syncSavedButtons = () => {
    document.querySelectorAll('[data-save-property]').forEach((button) => {
      const id = button.dataset.saveProperty || '';
      const initialText = button.dataset.initialText || button.textContent || '';
      const selectedText = button.dataset.toggleText || initialText;
      button.dataset.initialText = initialText;
      button.classList.toggle('is-selected', savedItems.has(id));
      button.setAttribute('aria-pressed', String(savedItems.has(id)));
      button.textContent = savedItems.has(id) ? selectedText : initialText;
    });
  };

  const syncFavouriteList = () => {
    const items = document.querySelectorAll('[data-favourite-item]');
    const empty = document.querySelector('[data-favourite-empty]');
    const count = document.querySelector('[data-favourite-count]');
    let visible = 0;

    items.forEach((item) => {
      const selected = savedItems.has(item.dataset.favouriteItem || '');
      item.hidden = !selected;
      if (selected) visible += 1;
    });

    if (empty) empty.hidden = visible > 0;
    if (count) count.textContent = String(visible);
  };

  const replaceSavedItems = (items) => {
    savedItems.clear();
    items.forEach((id) => {
      if (typeof id === 'string' && id) savedItems.add(id);
    });
    syncSavedButtons();
    syncFavouriteList();
  };

  const favouriteWorkspace = document.querySelector('[data-favourite-list]');
  if (favouriteWorkspace) favouriteWorkspace.setAttribute('aria-busy', 'true');
  syncSavedButtons();
  syncFavouriteList();

  fetchFavourites()
    .then((payload) => replaceSavedItems(payload.items))
    .catch(() => {
      document.documentElement.dataset.favouritesState = 'unavailable';
    })
    .finally(() => favouriteWorkspace?.removeAttribute('aria-busy'));

  document.addEventListener('click', async (event) => {
    const target = event.target instanceof Element ? event.target.closest('[data-save-property]') : null;
    if (!(target instanceof HTMLElement)) return;

    const id = target.dataset.saveProperty || '';
    if (!id || target.dataset.state === 'pending') return;

    target.dataset.state = 'pending';
    target.setAttribute('aria-busy', 'true');
    if ('disabled' in target) target.disabled = true;

    try {
      const body = new URLSearchParams({ public_id: id });
      const payload = await fetchFavourites({
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body,
      });
      replaceSavedItems(payload.items);
      target.dataset.state = 'ready';
      document.documentElement.dataset.favouritesState = 'ready';
    } catch (error) {
      target.dataset.state = 'error';
      document.documentElement.dataset.favouritesState = 'unavailable';
    } finally {
      target.removeAttribute('aria-busy');
      if ('disabled' in target) target.disabled = false;
    }
  });

  document.querySelectorAll('[data-toggle-text]:not([data-save-property])').forEach((button) => {
    const initialText = button.textContent || '';
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
      if (intent && input instanceof HTMLInputElement) input.value = intent;
    });
  });

  const campaign = campaignFromLocation();
  document.querySelectorAll('form[method="post"], form:not([method])').forEach((form) => {
    Object.entries(campaign).forEach(([name, value]) => {
      if (!value || form.querySelector(`[name="${name}"]`)) return;
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      input.value = value;
      form.appendChild(input);
    });
  });

  document.addEventListener('click', (event) => {
    const link = event.target instanceof Element ? event.target.closest('a, [data-analytics-event]') : null;
    if (!(link instanceof HTMLElement)) return;

    const eventType = analyticsEventFor(link);
    if (!eventType) return;

    const payload = new FormData();
    payload.set('event_type', eventType);
    payload.set('property_id', document.body.dataset.propertyId || '');
    payload.set('source_page', window.location.pathname + window.location.search);
    payload.set('target', link.getAttribute('href') || '');
    payload.set('label', (link.textContent || '').trim());
    payload.set('utm_source', campaign.utm_source || '');
    payload.set('utm_medium', campaign.utm_medium || '');
    payload.set('utm_campaign', campaign.utm_campaign || '');

    if (navigator.sendBeacon) {
      navigator.sendBeacon('/analytics/track', payload);
      return;
    }

    fetch('/analytics/track', {
      method: 'POST',
      body: payload,
      credentials: 'same-origin',
      keepalive: true,
    }).catch(() => {});
  });

  document.addEventListener('tn:content-updated', () => {
    syncSavedButtons();
    syncFavouriteList();
  });
};
