(function () {
  'use strict';

  document.querySelectorAll('[data-spatial-upload]').forEach(function (form) {
    var zone = form.querySelector('[data-spatial-dropzone]');
    var input = form.querySelector('[data-spatial-file]');
    var fileName = form.querySelector('[data-spatial-file-name]');
    var progress = form.querySelector('[data-spatial-progress]');
    var bar = progress ? progress.querySelector('span') : null;
    var percent = progress ? progress.querySelector('strong') : null;
    var status = form.querySelector('[data-spatial-upload-status]');

    function setFile(file) {
      if (!file) return;
      var transfer = new DataTransfer();
      transfer.items.add(file);
      input.files = transfer.files;
      fileName.textContent = file.name + ' / ' + (file.size / 1048576).toFixed(1) + ' MB';
      zone.classList.add('has-file');
    }

    ['dragenter', 'dragover'].forEach(function (eventName) {
      zone.addEventListener(eventName, function (event) {
        event.preventDefault();
        zone.classList.add('is-dragging');
      });
    });
    ['dragleave', 'drop'].forEach(function (eventName) {
      zone.addEventListener(eventName, function (event) {
        event.preventDefault();
        zone.classList.remove('is-dragging');
      });
    });
    zone.addEventListener('drop', function (event) { setFile(event.dataTransfer.files[0]); });
    zone.addEventListener('keydown', function (event) {
      if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); input.click(); }
    });
    input.addEventListener('change', function () { setFile(input.files[0]); });

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      if (!input.files.length) return;
      var request = new XMLHttpRequest();
      request.open('POST', form.action);
      request.setRequestHeader('Accept', 'application/json');
      request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      progress.hidden = false;
      status.classList.remove('is-visible');
      request.upload.addEventListener('progress', function (upload) {
        if (!upload.lengthComputable) return;
        var value = Math.round((upload.loaded / upload.total) * 100);
        bar.style.width = value + '%';
        percent.textContent = value + '%';
      });
      request.addEventListener('load', function () {
        var response = {};
        try { response = JSON.parse(request.responseText); } catch (error) { response.message = 'Сервер повернув некоректну відповідь.'; }
        status.textContent = response.message || (request.status < 300 ? 'Asset завантажено.' : 'Завантаження не вдалося.');
        status.classList.add('is-visible');
        if (request.status >= 200 && request.status < 300) window.setTimeout(function () { window.location.reload(); }, 700);
      });
      request.addEventListener('error', function () {
        status.textContent = 'З’єднання перервано. Повторіть завантаження.';
        status.classList.add('is-visible');
      });
      request.send(new FormData(form));
    });
  });
}());
