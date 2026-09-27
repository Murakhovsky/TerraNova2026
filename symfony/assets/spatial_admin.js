document.querySelectorAll('[data-spatial-upload]').forEach((form) => {
  const zone = form.querySelector('[data-spatial-dropzone]');
  const input = form.querySelector('[data-spatial-file]');
  const fileName = form.querySelector('[data-spatial-file-name]');
  const progress = form.querySelector('[data-spatial-progress]');
  const bar = progress?.querySelector('span');
  const percent = progress?.querySelector('strong');
  const status = form.querySelector('[data-spatial-upload-status]');

  if (!zone || !input) return;

  const setFile = (file) => {
    if (!file) return;
    const transfer = new DataTransfer();
    transfer.items.add(file);
    input.files = transfer.files;
    if (fileName) fileName.textContent = `${file.name} / ${(file.size / 1048576).toFixed(1)} MB`;
    zone.classList.add('has-file');
  };

  ['dragenter', 'dragover'].forEach((eventName) => {
    zone.addEventListener(eventName, (event) => {
      event.preventDefault();
      zone.classList.add('is-dragging');
    });
  });
  ['dragleave', 'drop'].forEach((eventName) => {
    zone.addEventListener(eventName, (event) => {
      event.preventDefault();
      zone.classList.remove('is-dragging');
    });
  });

  zone.addEventListener('drop', (event) => setFile(event.dataTransfer?.files?.[0]));
  zone.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      input.click();
    }
  });
  input.addEventListener('change', () => setFile(input.files?.[0]));

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    if (!input.files?.length) return;

    const request = new XMLHttpRequest();
    request.open('POST', form.action);
    request.setRequestHeader('Accept', 'application/json');
    request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

    if (progress) progress.hidden = false;
    status?.classList.remove('is-visible');

    request.upload.addEventListener('progress', (upload) => {
      if (!upload.lengthComputable) return;
      const value = Math.round((upload.loaded / upload.total) * 100);
      if (bar) bar.style.width = `${value}%`;
      if (percent) percent.textContent = `${value}%`;
    });

    request.addEventListener('load', () => {
      let response = {};
      try {
        response = JSON.parse(request.responseText);
      } catch {
        response.message = 'Сервер повернув некоректну відповідь.';
      }
      if (status) {
        status.textContent = response.message || (request.status < 300 ? 'Asset завантажено.' : 'Завантаження не вдалося.');
        status.classList.add('is-visible');
      }
      if (request.status >= 200 && request.status < 300) {
        window.setTimeout(() => window.location.reload(), 700);
      }
    });

    request.addEventListener('error', () => {
      if (!status) return;
      status.textContent = 'Зʼєднання перервано. Повторіть завантаження.';
      status.classList.add('is-visible');
    });

    request.send(new FormData(form));
  });
});
