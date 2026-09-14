import{t as e}from"./client-DTvB7HO0.js";(()=>{let t=e=>String(e??``).replace(/[&<>"']/g,e=>({"&":`&amp;`,"<":`&lt;`,">":`&gt;`,'"':`&quot;`,"'":`&#039;`})[e]);(async()=>{let n=document.querySelector(`[data-featured-section]`),r=document.querySelector(`[data-featured-grid]`);if(!(!n||!r))try{let i=(await e(n.dataset.apiUrl||`/api/v1/properties/featured?limit=4`)).data.properties||[];if(!i.length){r.innerHTML=`<div class="tn-empty-state"><h2>Об’єкти готуються</h2><p>Після публікації вони з’являться тут автоматично.</p></div>`;return}r.innerHTML=i.map(e=>`
        <article class="tn-project-card">
          <img src="${t(e.cover_url||`https://images.unsplash.com/photo-1600566753190-17f0baa2a6c3?auto=format&fit=crop&w=1200&q=82`)}" alt="${t(e.title)}">
          <button type="button" data-save-property="${t(e.public_id)}" data-toggle-text="✓" aria-label="Додати у вибрані">♡</button>
          <span class="tn-status-badge">Активно</span>
          <div>
            <h3>${t(e.title)}</h3>
            <p>${t(e.city)}${e.area_label?` · ${t(e.area_label)} м²`:``}</p>
            <strong>${t(e.price_label)}</strong>
            <a href="${t(e.url)}" aria-label="Відкрити об'єкт">→</a>
          </div>
        </article>
      `).join(``),document.dispatchEvent(new CustomEvent(`tn:content-updated`))}catch{r.innerHTML=`<div class="tn-empty-state"><h2>Каталог тимчасово недоступний</h2><p>Спробуйте оновити сторінку трохи пізніше.</p></div>`}})();let n=document.querySelector(`.tn-location-panel`);if(!n)return;let r=n.querySelector(`[data-location-title]`),i=n.querySelector(`[data-location-summary]`),a=n.querySelector(`[data-location-projects]`),o=n.querySelector(`[data-location-objects]`),s=n.querySelector(`[data-location-link]`),c=n.querySelector(`[data-location-list]`),l=document.querySelectorAll(`[data-location-choice]`),u=e=>{let t=Number(e);return t===1?`локація`:t>1&&t<5?`локації`:`локацій`},d=e=>{try{return JSON.parse(e.dataset.locationGroups||`[]`)}catch{return[]}},f=e=>{if(c){if(!e.length){c.innerHTML=`<article><div><strong>Локації готуються</strong><span>Список проєктів з’явиться після публікації.</span><small>Скоро</small></div></article>`;return}c.innerHTML=e.map(e=>`
      <article>
        ${e.image?`<img src="${t(e.image)}" alt="${t(e.title)}">`:``}
        <div>
          <strong>${t(e.title)}</strong>
          <span>${t(e.description)}</span>
          <small>${t(e.meta)} · ${t(e.status)}</small>
        </div>
      </article>
    `).join(``)}},p=e=>{l.forEach(t=>t.classList.toggle(`is-active`,t===e));let t=d(e);r&&(r.textContent=e.dataset.locationTitle||``),i&&(i.textContent=e.dataset.locationSummary||``),a&&(a.textContent=`${e.dataset.locationProjects||t.length||0} ${u(e.dataset.locationProjects||t.length)}`),o&&(o.textContent=`${e.dataset.locationObjects||0} об'єктів`),s&&(s.href=e.href),f(t)};l.forEach(e=>{e.addEventListener(`mouseenter`,()=>p(e)),e.addEventListener(`focus`,()=>p(e)),e.addEventListener(`click`,t=>{t.preventDefault(),p(e)})})})();