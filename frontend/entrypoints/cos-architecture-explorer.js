import '../features/cos/architecture-explorer.css';

function parseEmbeddedPayload(source) {
  if (!source) return { views: {}, summary: {}, default_view: '' };
  try {
    const parsed = JSON.parse(source.textContent || '{}');
    return parsed && typeof parsed === 'object' ? parsed : { views: {}, summary: {}, default_view: '' };
  } catch {
    return { views: {}, summary: {}, default_view: '' };
  }
}

function hasGraphElements(graph) {
  return Boolean(graph && Array.isArray(graph.elements) && graph.elements.length);
}

async function requestProjection(endpoint, mode) {
  const url = new URL(endpoint, window.location.href);
  url.searchParams.set('view', mode);
  url.searchParams.set('depth', 'all');

  const response = await fetch(url.toString(), {
    headers: { Accept: 'application/json' },
    credentials: 'same-origin',
  });
  const result = await response.json();
  if (!response.ok || !result?.ok || !result?.graph) {
    throw new Error(result?.error || `Architecture projection ${mode} failed to load.`);
  }
  return result.graph;
}

async function hydrateArchitecturePayload() {
  const root = document.querySelector('[data-architecture-explorer]');
  if (!root) return;

  let source = document.getElementById('cos-architecture-data');
  const payload = parseEmbeddedPayload(source);
  const embeddedViews = payload.views && typeof payload.views === 'object' ? payload.views : {};
  const activeMode = root.querySelector('[data-architecture-mode].is-active')?.dataset.architectureMode || '';
  const firstMode = root.querySelector('[data-architecture-mode]')?.dataset.architectureMode || '';
  const defaultView = root.dataset.architectureDefaultView
    || (typeof payload.default_view === 'string' ? payload.default_view : '')
    || activeMode
    || firstMode
    || Object.keys(embeddedViews)[0]
    || '';

  if (!defaultView || hasGraphElements(embeddedViews[defaultView])) return;

  const endpoint = root.dataset.architectureEndpoint || '';
  if (!endpoint) return;

  const modes = [...root.querySelectorAll('[data-architecture-mode]')]
    .map((button) => button.dataset.architectureMode || '')
    .filter(Boolean);
  if (!modes.includes(defaultView)) modes.unshift(defaultView);

  const recoveredViews = { ...embeddedViews };
  const results = await Promise.all(modes.map(async (mode) => {
    try {
      return [mode, await requestProjection(endpoint, mode)];
    } catch (exception) {
      console.warn(`COS Architecture Explorer could not hydrate ${mode}.`, exception);
      return [mode, null];
    }
  }));

  results.forEach(([mode, graph]) => {
    if (hasGraphElements(graph)) recoveredViews[mode] = graph;
  });

  if (!hasGraphElements(recoveredViews[defaultView])) return;

  const recoveredPayload = {
    ...payload,
    views: recoveredViews,
    default_view: defaultView,
    summary: payload.summary && Object.keys(payload.summary).length
      ? payload.summary
      : recoveredViews[defaultView]?.summary || {},
  };

  if (!source) {
    source = document.createElement('script');
    source.type = 'application/json';
    source.id = 'cos-architecture-data';
    root.appendChild(source);
  }
  source.textContent = JSON.stringify(recoveredPayload);
}

async function bootArchitectureExplorer() {
  try {
    await hydrateArchitecturePayload();
  } catch (exception) {
    console.warn('COS Architecture Explorer hydration fallback failed.', exception);
  }

  await import('../features/cos/architecture-explorer.js');
}

bootArchitectureExplorer();
