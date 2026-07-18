/**
 * Typed-ish API client for the Product Store backend.
 *
 * Every method returns the parsed `data` payload on success and throws an
 * ApiError (carrying status, code and field-level details) on failure, so
 * UI code can handle loading/success/error states uniformly.
 */

import { buildQuery } from './utils.js';

const TOKEN_KEY = 'product_store_token';
const USER_KEY = 'product_store_user';

export class ApiError extends Error {
  constructor(status, code, message, details = {}) {
    super(message);
    this.status = status;
    this.code = code;
    this.details = details;
  }
}

export const auth = {
  token: () => localStorage.getItem(TOKEN_KEY),
  user: () => JSON.parse(localStorage.getItem(USER_KEY) || 'null'),
  save(token, user) {
    localStorage.setItem(TOKEN_KEY, token);
    localStorage.setItem(USER_KEY, JSON.stringify(user));
  },
  clear() {
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(USER_KEY);
  },
};

/** Fired when the API answers 401 so the app can return to the login view. */
export const UNAUTHORIZED_EVENT = 'api:unauthorized';

async function request(method, path, { body, query } = {}) {
  const headers = { Accept: 'application/json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  const token = auth.token();
  if (token) headers.Authorization = `Bearer ${token}`;

  let response;
  try {
    response = await fetch(`/api${path}${query ? buildQuery(query) : ''}`, {
      method,
      headers,
      body: body !== undefined ? JSON.stringify(body) : undefined,
    });
  } catch {
    throw new ApiError(0, 'NETWORK_ERROR', 'Could not reach the server. Is the API running?');
  }

  if (response.status === 204) return null;

  let payload = null;
  try {
    payload = await response.json();
  } catch {
    throw new ApiError(response.status, 'BAD_RESPONSE', 'Server returned an invalid response.');
  }

  if (!response.ok) {
    const err = payload?.error ?? {};
    if (response.status === 401 && path !== '/auth/login') {
      auth.clear();
      window.dispatchEvent(new CustomEvent(UNAUTHORIZED_EVENT));
    }
    throw new ApiError(response.status, err.code ?? 'ERROR', err.message ?? 'Request failed.', err.details ?? {});
  }

  return payload;
}

export const api = {
  async login(email, password) {
    const payload = await request('POST', '/auth/login', { body: { email, password } });
    auth.save(payload.data.token, payload.data.user);
    return payload.data.user;
  },

  me: () => request('GET', '/auth/me'),

  /** @returns {Promise<{data: Array, meta: Object}>} */
  listProducts: (query) => request('GET', '/products', { query }),

  getCategories: async () => (await request('GET', '/products/categories')).data.categories,

  createProduct: async (data) => (await request('POST', '/products', { body: data })).data,

  updateProduct: async (id, data) => (await request('PUT', `/products/${id}`, { body: data })).data,

  deleteProduct: (id) => request('DELETE', `/products/${id}`),
};
