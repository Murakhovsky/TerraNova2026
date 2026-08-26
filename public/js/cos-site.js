(() => {
  const header = document.querySelector('.cos-header');
  const menu = document.querySelector('[data-cos-menu]');

  if (menu && header) {
    menu.addEventListener('click', () => {
      const open = header.classList.toggle('is-open');
      menu.setAttribute('aria-expanded', String(open));
    });
  }

  document.querySelectorAll('[data-language-switch]').forEach((select) => {
    select.addEventListener('change', () => {
      const page = window.COS_PAGE || {};
      const suffix = page.type === 'domain' && page.slug ? `/domains/${page.slug}` : '';
      window.location.href = `${page.base || '/cos/'}${select.value}${suffix}`;
    });
  });

  document.querySelectorAll('[data-domain-filter]').forEach((button) => {
    button.addEventListener('click', () => {
      document.querySelectorAll('[data-domain-filter]').forEach((item) => item.classList.remove('is-active'));
      button.classList.add('is-active');

      const filter = button.dataset.domainFilter;
      document.querySelectorAll('[data-domain-industries]').forEach((card) => {
        const industries = (card.dataset.domainIndustries || '').split(' ');
        card.hidden = filter !== 'all' && !industries.includes(filter);
      });
    });
  });
})();
