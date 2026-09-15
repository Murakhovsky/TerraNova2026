const MERMAID_VERSION = '11.17.2';
const MERMAID_SRC = `https://cdn.jsdelivr.net/npm/mermaid@${MERMAID_VERSION}/dist/mermaid.min.js`;

let loaderPromise = null;
let renderQueue = Promise.resolve();
let renderCounter = 0;

function loadMermaid() {
  if (typeof window === 'undefined') {
    return Promise.reject(new Error('Mermaid is available only in the browser.'));
  }

  if (window.mermaid) return Promise.resolve(window.mermaid);
  if (loaderPromise) return loaderPromise;

  loaderPromise = new Promise((resolve, reject) => {
    const existing = document.querySelector(`script[data-cos-mermaid="${MERMAID_VERSION}"]`);
    if (existing) {
      existing.addEventListener('load', () => resolve(window.mermaid), { once: true });
      existing.addEventListener('error', () => reject(new Error('Failed to load Mermaid runtime.')), { once: true });
      return;
    }

    const script = document.createElement('script');
    script.src = MERMAID_SRC;
    script.async = true;
    script.dataset.cosMermaid = MERMAID_VERSION;
    script.onload = () => {
      if (!window.mermaid) {
        reject(new Error('Mermaid runtime loaded without exposing window.mermaid.'));
        return;
      }
      resolve(window.mermaid);
    };
    script.onerror = () => reject(new Error(`Failed to load Mermaid ${MERMAID_VERSION}.`));
    document.head.appendChild(script);
  });

  return loaderPromise;
}

export function mermaidVersion() {
  return MERMAID_VERSION;
}

export function renderMermaid(source, dark) {
  const task = async () => {
    const mermaid = await loadMermaid();
    mermaid.initialize({
      startOnLoad: false,
      securityLevel: 'strict',
      theme: dark ? 'dark' : 'neutral',
      flowchart: {
        htmlLabels: false,
        useMaxWidth: true,
      },
      sequence: {
        useMaxWidth: true,
      },
    });

    renderCounter += 1;
    return mermaid.render(`cos-mermaid-${renderCounter}`, source);
  };

  const result = renderQueue.then(task);
  renderQueue = result.then(() => undefined, () => undefined);
  return result;
}
