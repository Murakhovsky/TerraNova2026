import { requestJson } from '../api/client.js';

(() => {
  const grid = document.querySelector('[data-catalog-grid]');

  if (!grid) {
    return;
  }

  const fallbackImage = 'https://images.unsplash.com/photo-1600585154340-be6161a56a0c?auto=format&fit=crop&w=900&q=80';
  const apiUrl = grid.dataset.apiUrl || '/api/v1/properties';
  const countNodes = document.querySelectorAll('[data-catalog-count]');
  const titleNode = document.querySelector('[data-catalog-results-title]');
  const emptyNode = document.querySelector('[data-catalog-empty]');
  const paginationNode = document.querySelector('[data-catalog-pagination]');
  const statNodes = {
    total: document.querySelector('[data-catalog-stat="total"]'),
    price_min: document.querySelector('[data-catalog-stat="price_min"]'),
    price_max: document.querySelector('[data-catalog-stat="price_max"]'),
    area_avg: document.querySelector('[data-catalog-stat="area_avg"]'),
  };

  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;',
  })[char]);

  const moneyLabel = (amount, currency = 'USD') => {
    if (amount === null || amount === undefined || amount === '') {
      return 'Ціна за запитом';
    }

    return `${Number(amount).toLocaleString('uk-UA', { maximumFractionDigits: 0 })} ${currency}`;
  };

  const publicUrl = (query) => {
    const params = new URLSearchParams(query);
    const suffix = params.toString();
    return `/property/catalog${suffix ? `?${suffix}` : ''}`;
  };

  const apiRequestUrl = (query) => {
    const params = new URLSearchParams(query);
    const suffix = params.toString();
    return `${apiUrl}${suffix ? `?${suffix}` : ''}`;
  };

  const queryFromLocation = () => new URLSearchParams(window.location.search);

  const queryFromForm = (form) => {
    const params = new URLSearchParams();
    new FormData(form).forEach((value, key) => {
      if (value !== '') {
        params.set(key, value);
      }
    });
    return params;
  };

  const setLoading = () => {
    grid.setAttribute('aria-busy', 'true');
    grid.innerHTML = '<div class="tn-empty-state"><h2>Завантажуємо об’єкти</h2><p>Оновлюємо каталог за обраними фільтрами.</p></div>';
    if (emptyNode) {
      emptyNode.hidden = true;
    }
  };

  const renderCard = (property) => `
    <article class="tn-property-card" itemscope itemtype="https://schema.org/Product">
      <a class="tn-property-card__image" href="${escapeHtml(property.url)}" itemprop="url">
        <img src="${escapeHtml(property.cover_url || fallbackImage)}" alt="${escapeHtml(property.title)}" itemprop="image">
        ${Number(property.is_featured) === 1 ? '<span class="tn-card-badge">Top</span>' : ''}
        <span class="tn-card-media-badge">${Number(property.image_count) > 0 ? `${Number(property.image_count)} фото` : 'фото готується'}</span>
        <span class="tn-card-price-badge">${escapeHtml(property.price_label)}</span>
      </a>
      <div class="tn-property-card__body">
        <div class="tn-card-tags">
          <span>${escapeHtml(property.deal_label)}</span>
          <span>${escapeHtml(property.type_name)}</span>
          ${Number(property.has_3d_tour) === 1 ? '<span>3D tour</span>' : ''}
        </div>
        <h2 itemprop="name"><a href="${escapeHtml(property.url)}">${escapeHtml(property.title)}</a></h2>
        <p itemprop="description">${escapeHtml(property.short_description)}</p>
        <div class="tn-property-facts">
          <span>${escapeHtml(property.city)}</span>
          ${property.area_label ? `<span>${escapeHtml(property.area_label)} м²</span>` : ''}
          ${property.rooms_label ? `<span>${escapeHtml(property.rooms_label)} кімн.</span>` : ''}
          ${property.status ? `<span>${escapeHtml(property.status)}</span>` : ''}
          ${property.source_type ? `<span>${escapeHtml(property.source_type)}</span>` : ''}
        </div>
        <div class="tn-property-meta" itemprop="offers" itemscope itemtype="https://schema.org/Offer">
          <strong>${escapeHtml(property.price_label)}</strong>
          ${property.price_amount ? `<meta itemprop="price" content="${escapeHtml(property.price_amount)}"><meta itemprop="priceCurrency" content="${escapeHtml(property.price_currency)}">` : ''}
          <span>${escapeHtml(property.public_id)}</span>
        </div>
        <div class="tn-card-actions">
          <a class="tn-btn tn-btn--dark" href="${escapeHtml(property.url)}">Відкрити</a>
          <a class="tn-btn tn-btn--ghost" href="#request">Запит</a>
          <button type="button" data-save-property="${escapeHtml(property.public_id)}" data-toggle-text="У вибраному" aria-label="Додати у вибране">♡</button>
        </div>
      </div>
    </article>
  `;

  const renderPagination = (pagination, query) => {
    if (!paginationNode) {
      return;
    }

    if (!pagination || Number(pagination.total_pages || 1) <= 1) {
      paginationNode.innerHTML = '';
      return;
    }

    const page = Number(pagination.page || 1);
    const totalPages = Number(pagination.total_pages || 1);
    const previousQuery = new URLSearchParams(query);
    previousQuery.set('page', String(Math.max(1, page - 1)));
    const nextQuery = new URLSearchParams(query);
    nextQuery.set('page', String(Math.min(totalPages, page + 1)));

    paginationNode.innerHTML = `
      ${pagination.has_previous ? `<a class="tn-btn tn-btn--ghost" href="${publicUrl(previousQuery)}" data-catalog-page="${page - 1}">Назад</a>` : ''}
      <span>${page} / ${totalPages}</span>
      ${pagination.has_next ? `<a class="tn-btn tn-btn--dark" href="${publicUrl(nextQuery)}" data-catalog-page="${page + 1}">Далі</a>` : ''}
    `;
  };

  const renderPayload = (payload, query) => {
    const data = payload.data || {};
    const properties = data.properties || [];
    const pagination = data.pagination || {};
    const stats = data.stats || {};
    const total = Number(pagination.total || stats.total || properties.length || 0);

    countNodes.forEach((node) => {
      node.textContent = String(total);
    });

    if (titleNode) {
      titleNode.textContent = `${total} об’єктів`;
    }

    if (statNodes.total) {
      statNodes.total.textContent = String(stats.total ?? total);
    }
    if (statNodes.price_min) {
      statNodes.price_min.textContent = moneyLabel(stats.price_min);
    }
    if (statNodes.price_max) {
      statNodes.price_max.textContent = moneyLabel(stats.price_max);
    }
    if (statNodes.area_avg) {
      statNodes.area_avg.textContent = stats.area_avg ? `${Number(stats.area_avg).toLocaleString('uk-UA')} м²` : '—';
    }

    grid.removeAttribute('aria-busy');
    grid.innerHTML = properties.length
      ? properties.map(renderCard).join('')
      : '';

    if (emptyNode) {
      emptyNode.hidden = properties.length > 0;
    }

    renderPagination(pagination, query);
    document.dispatchEvent(new CustomEvent('tn:content-updated'));
  };

  const loadCatalog = async (query, replaceUrl = false) => {
    setLoading();

    try {
      const payload = await requestJson(apiRequestUrl(query));

      if (replaceUrl) {
        window.history.pushState({}, '', publicUrl(query));
      }

      renderPayload(payload, query);
    } catch (error) {
      grid.removeAttribute('aria-busy');
      grid.innerHTML = '<div class="tn-empty-state"><h2>Каталог тимчасово недоступний</h2><p>Спробуйте оновити сторінку трохи пізніше.</p></div>';
      if (paginationNode) {
        paginationNode.innerHTML = '';
      }
    }
  };

  document.querySelectorAll('[data-catalog-form]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      loadCatalog(queryFromForm(form), true);
    });
  });

  document.addEventListener('click', (event) => {
    const link = event.target instanceof Element ? event.target.closest('[data-catalog-page]') : null;

    if (!link) {
      return;
    }

    event.preventDefault();
    const query = queryFromLocation();
    query.set('page', link.getAttribute('data-catalog-page') || '1');
    loadCatalog(query, true);
  });

  window.addEventListener('popstate', () => loadCatalog(queryFromLocation(), false));
  loadCatalog(queryFromLocation(), false);
})();
