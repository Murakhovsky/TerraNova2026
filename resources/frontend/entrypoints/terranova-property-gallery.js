(() => {
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

})();
