const TELEMETRY_ENDPOINT = '/telemetry/web';

const sendTelemetry = (payload) => {
  const body = JSON.stringify({
    ...payload,
    path: window.location.pathname,
    surface: document.body?.dataset.interfaceSurface || 'web',
  });

  if (navigator.sendBeacon) {
    navigator.sendBeacon(TELEMETRY_ENDPOINT, new Blob([body], { type: 'application/json' }));
    return;
  }

  fetch(TELEMETRY_ENDPOINT, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body,
    keepalive: true,
    credentials: 'same-origin',
  }).catch(() => {});
};

export const initWebTelemetry = () => {
  if (document.documentElement.dataset.cosTelemetry === 'ready') return;
  document.documentElement.dataset.cosTelemetry = 'ready';

  window.addEventListener('error', (event) => {
    sendTelemetry({
      type: 'error',
      message: String(event.message || 'browser_error').slice(0, 500),
      source: String(event.filename || '').slice(0, 240),
    });
  });

  window.addEventListener('unhandledrejection', (event) => {
    const reason = event.reason instanceof Error ? event.reason.message : String(event.reason || 'unhandled_rejection');
    sendTelemetry({
      type: 'unhandledrejection',
      message: reason.slice(0, 500),
    });
  });

  window.addEventListener('load', () => {
    const navigation = performance.getEntriesByType('navigation')[0];
    if (navigation) {
      sendTelemetry({
        type: 'navigation',
        duration_ms: Math.round(navigation.duration),
        value: Math.round(navigation.transferSize || 0),
      });
    }
  }, { once: true });
};
