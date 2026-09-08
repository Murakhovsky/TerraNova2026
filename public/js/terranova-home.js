(() => {
  const fallbackImage = 'https://images.unsplash.com/photo-1600566753190-17f0baa2a6c3?auto=format&fit=crop&w=1200&q=82';
  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;',
  })[char]);

  const renderFeatured = async () => {
    const section = document.querySelector('[data-featured-section]');
    const grid = document.querySelector('[data-featured-grid]');

    if (!section || !grid) {
      return;
    }

    try {
      const response = await fetch(section.dataset.apiUrl || '/api/property/featured?limit=4', {
        headers: { Accept: 'application/json' },
      });
      const payload = await response.json();

      if (!response.ok || !payload.ok) {
        throw new Error(payload.message || 'Featured API failed');
      }

      const properties = payload.data.properties || [];
      if (!properties.length) {
        grid.innerHTML = '<div class="tn-empty-state"><h2>Об’єкти готуються</h2><p>Після публікації вони з’являться тут автоматично.</p></div>';
        return;
      }

      grid.innerHTML = properties.map((property) => `
        <article class="tn-project-card">
          <img src="${escapeHtml(property.cover_url || fallbackImage)}" alt="${escapeHtml(property.title)}">
          <button type="button" data-save-property="${escapeHtml(property.public_id)}" data-toggle-text="✓" aria-label="Додати у вибрані">♡</button>
          <span class="tn-status-badge">Активно</span>
          <div>
            <h3>${escapeHtml(property.title)}</h3>
            <p>${escapeHtml(property.city)}${property.area_label ? ` · ${escapeHtml(property.area_label)} м²` : ''}</p>
            <strong>${escapeHtml(property.price_label)}</strong>
            <a href="${escapeHtml(property.url)}" aria-label="Відкрити об'єкт">→</a>
          </div>
        </article>
      `).join('');
      document.dispatchEvent(new CustomEvent('tn:content-updated'));
    } catch (error) {
      grid.innerHTML = '<div class="tn-empty-state"><h2>Каталог тимчасово недоступний</h2><p>Спробуйте оновити сторінку трохи пізніше.</p></div>';
    }
  };

  renderFeatured();

  const locationPanel = document.querySelector('.tn-location-panel');

  if (!locationPanel) {
    return;
  }

  const title = locationPanel.querySelector('[data-location-title]');
  const summary = locationPanel.querySelector('[data-location-summary]');
  const projects = locationPanel.querySelector('[data-location-projects]');
  const objects = locationPanel.querySelector('[data-location-objects]');
  const link = locationPanel.querySelector('[data-location-link]');
  const list = locationPanel.querySelector('[data-location-list]');
  const empty = document.querySelector('[data-location-empty]');
  const choices = Array.from(document.querySelectorAll('[data-location-choice]'));
  const filterButtons = Array.from(document.querySelectorAll('[data-type-filter]'));

  const pluralLocations = (count) => {
    const value = Number(count);
    if (value === 1) {
      return 'підлокація';
    }

    return value > 1 && value < 5 ? 'підлокації' : 'підлокацій';
  };

  const parseGroups = (choice) => {
    try {
      return JSON.parse(choice.dataset.locationGroups || '[]');
    } catch (error) {
      return [];
    }
  };

  const locationHasType = (choice, type) => {
    if (type === 'all') {
      return true;
    }

    return (choice.dataset.propertyTypes || '')
      .split(',')
      .map((item) => item.trim())
      .filter(Boolean)
      .includes(type);
  };

  const filterGroupsByType = (groups, type) => {
    if (type === 'all') {
      return groups;
    }

    return groups.filter((group) => Array.isArray(group.types) && group.types.includes(type));
  };

  const renderGroups = (groups) => {
    if (!list) {
      return;
    }

    if (!groups.length) {
      list.innerHTML = '<article><div><strong>Немає підлокацій</strong><span>Для вибраного типу нерухомості ця локація поки не має активних пропозицій.</span><small>Спробуйте інший фільтр</small></div></article>';
      return;
    }

    list.innerHTML = groups.map((group) => `
      <article>
        ${group.image ? `<img src="${escapeHtml(group.image)}" alt="${escapeHtml(group.title)}">` : ''}
        <div>
          <strong>${escapeHtml(group.title)}</strong>
          <span>${escapeHtml(group.description)}</span>
          <small>${escapeHtml(group.meta)} · ${escapeHtml(group.status)}</small>
        </div>
      </article>
    `).join('');
  };

  let activeType = 'all';

  const applyLocation = (choice) => {
    if (!choice || choice.hidden || choice.classList.contains('is-filtered-out')) {
      return;
    }

    choices.forEach((item) => item.classList.toggle('is-active', item === choice));
    const groups = filterGroupsByType(parseGroups(choice), activeType);
    const groupCount = groups.length;
    const objectCount = groups.reduce((sum, group) => sum + Number(group.objects || 0), 0);

    if (title) {
      title.textContent = choice.dataset.locationTitle || '';
    }

    if (summary) {
      summary.textContent = choice.dataset.locationSummary || '';
    }

    if (projects) {
      projects.textContent = `${groupCount} ${pluralLocations(groupCount)}`;
    }

    if (objects) {
      objects.textContent = `${objectCount} об'єктів`;
    }

    if (link) {
      const separator = choice.href.includes('?') ? '&' : '?';
      link.href = activeType === 'all' ? choice.href : `${choice.href}${separator}type=${encodeURIComponent(activeType)}`;
    }

    renderGroups(groups);
  };

  const applyTypeFilter = (type) => {
    activeType = type || 'all';
    let firstVisible = null;

    choices.forEach((choice) => {
      const isVisible = locationHasType(choice, activeType);
      choice.classList.toggle('is-filtered-out', !isVisible);
      choice.hidden = !isVisible;

      if (isVisible && !firstVisible) {
        firstVisible = choice;
      }
    });

    filterButtons.forEach((button) => {
      button.classList.toggle('is-active', button.dataset.typeFilter === activeType);
    });

    if (empty) {
      empty.hidden = Boolean(firstVisible);
    }

    if (firstVisible) {
      applyLocation(firstVisible);
    } else {
      choices.forEach((item) => item.classList.remove('is-active'));
      if (title) title.textContent = 'Немає локацій';
      if (summary) summary.textContent = 'Для цього типу нерухомості локації ще готуються.';
      if (projects) projects.textContent = '0 підлокацій';
      if (objects) objects.textContent = '0 об’єктів';
      if (link) link.href = filterButtons.find((button) => button.dataset.typeFilter === activeType)?.dataset.catalogUrl || '/property/catalog';
      renderGroups([]);
    }
  };

  choices.forEach((choice) => {
    choice.addEventListener('mouseenter', () => applyLocation(choice));
    choice.addEventListener('focus', () => applyLocation(choice));
    choice.addEventListener('click', (event) => {
      event.preventDefault();
      applyLocation(choice);
    });
  });

  filterButtons.forEach((button) => {
    button.addEventListener('click', () => applyTypeFilter(button.dataset.typeFilter || 'all'));
  });

  applyTypeFilter('all');
})();
