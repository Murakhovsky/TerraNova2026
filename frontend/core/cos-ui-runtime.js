import htmx from 'htmx.org/dist/htmx.esm.js';
import {
  Collapse,
  Dropdown,
  Modal,
  Offcanvas,
  Popover,
  Tab,
  Toast,
  Tooltip,
} from 'bootstrap';
import { resetFormState } from './production.js';

export const bootstrapUi = {
  Collapse,
  Dropdown,
  Modal,
  Offcanvas,
  Popover,
  Tab,
  Toast,
  Tooltip,
};

const mutationVerbs = new Set(['delete', 'patch', 'post', 'put']);

const requestElement = (event) => event.detail?.elt instanceof Element ? event.detail.elt : null;

const requestForm = (event) => {
  const element = requestElement(event);
  if (element instanceof HTMLFormElement) return element;
  return element?.closest('form') ?? null;
};

const csrfToken = (element) => {
  const scope = element?.closest('[data-csrf]');
  const scopedToken = scope?.getAttribute('data-csrf')?.trim();
  if (scopedToken) return scopedToken;

  const form = element instanceof HTMLFormElement ? element : element?.closest('form');
  const formToken = form?.querySelector('input[name="csrf_token"]')?.value?.trim();
  if (formToken) return formToken;

  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')?.trim() ?? '';
};

const setBusy = (element, busy) => {
  if (!(element instanceof Element)) return;
  if (busy) {
    element.setAttribute('aria-busy', 'true');
    return;
  }
  element.removeAttribute('aria-busy');
};

export const initBootstrapPresentation = (root = document) => {
  root.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((element) => Tooltip.getOrCreateInstance(element));
  root.querySelectorAll('[data-bs-toggle="popover"]').forEach((element) => Popover.getOrCreateInstance(element));
};

const initHtmxContract = () => {
  htmx.config.allowEval = false;
  htmx.config.allowScriptTags = false;
  htmx.config.selfRequestsOnly = true;
  htmx.config.historyCacheSize = 0;

  document.body.addEventListener('htmx:configRequest', (event) => {
    const verb = String(event.detail?.verb ?? '').toLowerCase();
    if (!mutationVerbs.has(verb)) return;

    const token = csrfToken(requestElement(event));
    if (!token) return;

    event.detail.parameters ??= {};
    if (!event.detail.parameters.csrf_token) event.detail.parameters.csrf_token = token;
    event.detail.headers ??= {};
    event.detail.headers['X-CSRF-Token'] = token;
  });

  document.body.addEventListener('htmx:beforeRequest', (event) => {
    const form = requestForm(event);
    setBusy(form ?? requestElement(event), true);
  });

  document.body.addEventListener('htmx:afterRequest', (event) => {
    const form = requestForm(event);
    if (form) {
      resetFormState(form);
      return;
    }
    setBusy(requestElement(event), false);
  });

  document.body.addEventListener('htmx:afterSwap', (event) => {
    const target = event.detail?.target instanceof Element ? event.detail.target : document;
    initBootstrapPresentation(target);
  });

  document.body.addEventListener('htmx:responseError', (event) => {
    const xhr = event.detail?.xhr;
    document.dispatchEvent(new CustomEvent('cos:request-error', {
      detail: {
        status: Number(xhr?.status ?? 0),
        path: String(event.detail?.pathInfo?.requestPath ?? ''),
      },
    }));
  });
};

export const initCosUiRuntime = () => {
  if (document.documentElement.dataset.cosUiRuntime === 'ready') return;
  document.documentElement.dataset.cosUiRuntime = 'ready';

  window.htmx = htmx;
  initBootstrapPresentation(document);
  initHtmxContract();
};
