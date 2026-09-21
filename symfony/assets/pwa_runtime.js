const UPDATE_READY_EVENT = 'cos:pwa-update-ready';
const APPLY_UPDATE_EVENT = 'cos:pwa-apply-update';

let registrationPromise = null;
let reloadOnControllerChange = false;

function announceUpdate(registration) {
  if (!registration.waiting) {
    return;
  }

  window.dispatchEvent(new CustomEvent(UPDATE_READY_EVENT, {
    detail: { registration }
  }));
}

function observeRegistration(registration) {
  announceUpdate(registration);

  registration.addEventListener('updatefound', () => {
    const worker = registration.installing;
    if (!worker) {
      return;
    }

    worker.addEventListener('statechange', () => {
      if (worker.state === 'installed' && navigator.serviceWorker.controller) {
        announceUpdate(registration);
      }
    });
  });

  return registration;
}

export function registerPwaRuntime() {
  if (!('serviceWorker' in navigator) || !window.isSecureContext) {
    return Promise.resolve(null);
  }

  if (registrationPromise) {
    return registrationPromise;
  }

  registrationPromise = navigator.serviceWorker
    .register('/sw.js', { scope: '/', updateViaCache: 'none' })
    .then(observeRegistration)
    .catch(() => null);

  return registrationPromise;
}

window.addEventListener('load', () => {
  registerPwaRuntime();
}, { once: true });

window.addEventListener(APPLY_UPDATE_EVENT, () => {
  registerPwaRuntime().then((registration) => {
    if (!registration?.waiting) {
      return;
    }

    reloadOnControllerChange = true;
    registration.waiting.postMessage({ type: 'SKIP_WAITING' });
  });
});

navigator.serviceWorker?.addEventListener('controllerchange', () => {
  if (reloadOnControllerChange) {
    window.location.reload();
  }
});
