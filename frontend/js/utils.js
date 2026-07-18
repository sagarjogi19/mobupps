/**
 * Pure utility functions (kept side-effect free so they can be unit tested).
 */

/**
 * Returns a debounced version of fn that fires only after `delay` ms of
 * inactivity. Used for the search inputs so we don't hit the API per keystroke.
 */
export function debounce(fn, delay = 400) {
  let timer = null;
  return function debounced(...args) {
    clearTimeout(timer);
    timer = setTimeout(() => fn.apply(this, args), delay);
  };
}

/**
 * Builds a query string from an object, skipping empty/null/undefined values.
 * buildQuery({q: 'mouse', page: 2, category: ''}) -> "?q=mouse&page=2"
 */
export function buildQuery(params) {
  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value === '' || value === null || value === undefined) continue;
    search.append(key, String(value));
  }
  const qs = search.toString();
  return qs ? `?${qs}` : '';
}

/** Formats a number as a USD price string. */
export function formatPrice(value) {
  return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(value);
}

/** Escapes a string for safe interpolation into innerHTML. */
export function escapeHtml(value) {
  return String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}
