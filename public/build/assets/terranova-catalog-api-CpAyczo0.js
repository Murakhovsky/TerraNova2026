import{t as e}from"./client-DTvB7HO0.js";(()=>{let t=document.querySelector(`[data-catalog-grid]`);if(!t)return;let n=t.dataset.apiUrl||`/api/v1/properties`,r=document.querySelectorAll(`[data-catalog-count]`),i=document.querySelector(`[data-catalog-results-title]`),a=document.querySelector(`[data-catalog-empty]`),o=document.querySelector(`[data-catalog-pagination]`),s={total:document.querySelector(`[data-catalog-stat="total"]`),price_min:document.querySelector(`[data-catalog-stat="price_min"]`),price_max:document.querySelector(`[data-catalog-stat="price_max"]`),area_avg:document.querySelector(`[data-catalog-stat="area_avg"]`)},c=e=>String(e??``).replace(/[&<>"']/g,e=>({"&":`&amp;`,"<":`&lt;`,">":`&gt;`,'"':`&quot;`,"'":`&#039;`})[e]),l=(e,t=`USD`)=>e==null||e===``?`Ціна за запитом`:`${Number(e).toLocaleString(`uk-UA`,{maximumFractionDigits:0})} ${t}`,u=e=>{let t=new URLSearchParams(e).toString();return`/property/catalog${t?`?${t}`:``}`},d=e=>{let t=new URLSearchParams(e).toString();return`${n}${t?`?${t}`:``}`},f=()=>new URLSearchParams(window.location.search),p=e=>{let t=new URLSearchParams;return new FormData(e).forEach((e,n)=>{e!==``&&t.set(n,e)}),t},m=()=>{t.setAttribute(`aria-busy`,`true`),t.innerHTML=`<div class="tn-empty-state"><h2>Завантажуємо об’єкти</h2><p>Оновлюємо каталог за обраними фільтрами.</p></div>`,a&&(a.hidden=!0)},h=e=>`
    <article class="tn-property-card" itemscope itemtype="https://schema.org/Product">
      <a class="tn-property-card__image" href="${c(e.url)}" itemprop="url">
        <img src="${c(e.cover_url||`https://images.unsplash.com/photo-1600585154340-be6161a56a0c?auto=format&fit=crop&w=900&q=80`)}" alt="${c(e.title)}" itemprop="image">
        ${Number(e.is_featured)===1?`<span class="tn-card-badge">Top</span>`:``}
        <span class="tn-card-media-badge">${Number(e.image_count)>0?`${Number(e.image_count)} фото`:`фото готується`}</span>
        <span class="tn-card-price-badge">${c(e.price_label)}</span>
      </a>
      <div class="tn-property-card__body">
        <div class="tn-card-tags">
          <span>${c(e.deal_label)}</span>
          <span>${c(e.type_name)}</span>
          ${Number(e.has_3d_tour)===1?`<span>3D tour</span>`:``}
        </div>
        <h2 itemprop="name"><a href="${c(e.url)}">${c(e.title)}</a></h2>
        <p itemprop="description">${c(e.short_description)}</p>
        <div class="tn-property-facts">
          <span>${c(e.city)}</span>
          ${e.area_label?`<span>${c(e.area_label)} м²</span>`:``}
          ${e.rooms_label?`<span>${c(e.rooms_label)} кімн.</span>`:``}
          ${e.status?`<span>${c(e.status)}</span>`:``}
          ${e.source_type?`<span>${c(e.source_type)}</span>`:``}
        </div>
        <div class="tn-property-meta" itemprop="offers" itemscope itemtype="https://schema.org/Offer">
          <strong>${c(e.price_label)}</strong>
          ${e.price_amount?`<meta itemprop="price" content="${c(e.price_amount)}"><meta itemprop="priceCurrency" content="${c(e.price_currency)}">`:``}
          <span>${c(e.public_id)}</span>
        </div>
        <div class="tn-card-actions">
          <a class="tn-btn tn-btn--dark" href="${c(e.url)}">Відкрити</a>
          <a class="tn-btn tn-btn--ghost" href="#request">Запит</a>
          <button type="button" data-save-property="${c(e.public_id)}" data-toggle-text="У вибраному" aria-label="Додати у вибране">♡</button>
        </div>
      </div>
    </article>
  `,g=(e,t)=>{if(!o)return;if(!e||Number(e.total_pages||1)<=1){o.innerHTML=``;return}let n=Number(e.page||1),r=Number(e.total_pages||1),i=new URLSearchParams(t);i.set(`page`,String(Math.max(1,n-1)));let a=new URLSearchParams(t);a.set(`page`,String(Math.min(r,n+1))),o.innerHTML=`
      ${e.has_previous?`<a class="tn-btn tn-btn--ghost" href="${u(i)}" data-catalog-page="${n-1}">Назад</a>`:``}
      <span>${n} / ${r}</span>
      ${e.has_next?`<a class="tn-btn tn-btn--dark" href="${u(a)}" data-catalog-page="${n+1}">Далі</a>`:``}
    `},_=(e,n)=>{let o=e.data||{},c=o.properties||[],u=o.pagination||{},d=o.stats||{},f=Number(u.total||d.total||c.length||0);r.forEach(e=>{e.textContent=String(f)}),i&&(i.textContent=`${f} об’єктів`),s.total&&(s.total.textContent=String(d.total??f)),s.price_min&&(s.price_min.textContent=l(d.price_min)),s.price_max&&(s.price_max.textContent=l(d.price_max)),s.area_avg&&(s.area_avg.textContent=d.area_avg?`${Number(d.area_avg).toLocaleString(`uk-UA`)} м²`:`—`),t.removeAttribute(`aria-busy`),t.innerHTML=c.length?c.map(h).join(``):``,a&&(a.hidden=c.length>0),g(u,n),document.dispatchEvent(new CustomEvent(`tn:content-updated`))},v=async(n,r=!1)=>{m();try{let t=await e(d(n));r&&window.history.pushState({},``,u(n)),_(t,n)}catch{t.removeAttribute(`aria-busy`),t.innerHTML=`<div class="tn-empty-state"><h2>Каталог тимчасово недоступний</h2><p>Спробуйте оновити сторінку трохи пізніше.</p></div>`,o&&(o.innerHTML=``)}};document.querySelectorAll(`[data-catalog-form]`).forEach(e=>{e.addEventListener(`submit`,t=>{t.preventDefault(),v(p(e),!0)})}),document.addEventListener(`click`,e=>{let t=e.target instanceof Element?e.target.closest(`[data-catalog-page]`):null;if(!t)return;e.preventDefault();let n=f();n.set(`page`,t.getAttribute(`data-catalog-page`)||`1`),v(n,!0)}),window.addEventListener(`popstate`,()=>v(f(),!1)),v(f(),!1)})();