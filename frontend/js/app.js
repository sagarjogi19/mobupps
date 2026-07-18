/**
 * Application entry point: view switching, product list state, modals,
 * and all event wiring. State lives in one plain object; every mutation
 * goes through loadProducts() so the UI stays consistent.
 */

import { api, auth, ApiError, UNAUTHORIZED_EVENT } from './api.js';
import { debounce, formatPrice, escapeHtml } from './utils.js';

const $ = (sel) => document.querySelector(sel);

const state = {
  page: 1,
  perPage: 10,
  q: '',
  category: '',
  minPrice: '',
  maxPrice: '',
  sort: 'created_at',
  order: 'desc',
  meta: null,
  products: [],
  editingId: null,   // null = create mode
  deletingId: null,
};

/* ================= view switching ================= */

function showLogin() {
  $('#login-view').classList.remove('hidden');
  $('#app-view').classList.add('hidden');
  $('#login-password').value = '';
  hide($('#login-error'));
}

async function showApp() {
  $('#login-view').classList.add('hidden');
  $('#app-view').classList.remove('hidden');
  $('#user-email').textContent = auth.user()?.email ?? '';
  await Promise.all([loadProducts(), loadCategories()]);
}

window.addEventListener(UNAUTHORIZED_EVENT, () => {
  toast('Your session expired. Please sign in again.', 'error');
  showLogin();
});

/* ================= helpers ================= */

function show(el) { el.classList.remove('hidden'); }
function hide(el) { el.classList.add('hidden'); }

function setBusy(button, busy) {
  button.disabled = busy;
  button.querySelector('.spinner')?.classList.toggle('hidden', !busy);
  button.querySelector('.btn-label')?.classList.toggle('dim', busy);
}

function toast(message, type = 'success') {
  const el = document.createElement('div');
  el.className = `toast toast-${type}`;
  el.textContent = message;
  $('#toast-container').appendChild(el);
  setTimeout(() => el.classList.add('leaving'), 3200);
  setTimeout(() => el.remove(), 3600);
}

/* ================= product list ================= */

async function loadProducts() {
  show($('#table-loading'));
  hide($('#list-status'));
  try {
    const { data, meta } = await api.listProducts({
      q: state.q,
      category: state.category,
      min_price: state.minPrice,
      max_price: state.maxPrice,
      sort: state.sort,
      order: state.order,
      page: state.page,
      per_page: state.perPage,
    });
    state.products = data;
    state.meta = meta;
    renderTable();
  } catch (err) {
    if (err instanceof ApiError && err.status === 401) return; // handled globally
    const status = $('#list-status');
    status.textContent = err.message;
    status.className = 'list-status alert alert-error';
    show(status);
  } finally {
    hide($('#table-loading'));
  }
}

function renderTable() {
  const tbody = $('#product-tbody');
  const { products, meta } = state;

  tbody.innerHTML = products.map((p) => `
    <tr data-id="${p.id}">
      <td>
        <div class="cell-name">${escapeHtml(p.name)}</div>
        <div class="cell-desc muted">${escapeHtml(p.description || '')}</div>
      </td>
      <td><code>${escapeHtml(p.sku)}</code></td>
      <td><span class="chip">${escapeHtml(p.category)}</span></td>
      <td class="num">${formatPrice(p.price)}</td>
      <td class="num">
        <span class="stock ${p.stock === 0 ? 'stock-out' : p.stock <= 10 ? 'stock-low' : ''}">${p.stock}</span>
      </td>
      <td class="actions-col">
        <button class="btn btn-ghost btn-sm" data-action="edit">Edit</button>
        <button class="btn btn-ghost btn-sm danger" data-action="delete">Delete</button>
      </td>
    </tr>`).join('');

  $('#empty-state').classList.toggle('hidden', products.length > 0);

  $('#page-info').textContent = meta
    ? `Page ${meta.page} of ${meta.total_pages} — ${meta.total} product${meta.total === 1 ? '' : 's'}`
    : '';
  $('#prev-page').disabled = !meta?.has_prev;
  $('#next-page').disabled = !meta?.has_next;
}

async function loadCategories() {
  try {
    const categories = await api.getCategories();
    const select = $('#category-filter');
    const current = select.value;
    select.innerHTML = '<option value="">All categories</option>' +
      categories.map((c) => `<option value="${escapeHtml(c)}">${escapeHtml(c)}</option>`).join('');
    select.value = current;
    $('#category-list').innerHTML = categories.map((c) => `<option value="${escapeHtml(c)}">`).join('');
  } catch {
    /* category dropdown is non-critical; the list still works without it */
  }
}

/* ================= filters & pagination events ================= */

const debouncedSearch = debounce(() => {
  state.page = 1;
  loadProducts();
}, 400);

$('#search-input').addEventListener('input', (e) => {
  state.q = e.target.value.trim();
  debouncedSearch();
});
$('#min-price').addEventListener('input', (e) => {
  state.minPrice = e.target.value;
  debouncedSearch();
});
$('#max-price').addEventListener('input', (e) => {
  state.maxPrice = e.target.value;
  debouncedSearch();
});
$('#category-filter').addEventListener('change', (e) => {
  state.category = e.target.value;
  state.page = 1;
  loadProducts();
});
$('#sort-select').addEventListener('change', (e) => {
  [state.sort, state.order] = e.target.value.split(':');
  state.page = 1;
  loadProducts();
});
$('#prev-page').addEventListener('click', () => {
  if (state.meta?.has_prev) { state.page -= 1; loadProducts(); }
});
$('#next-page').addEventListener('click', () => {
  if (state.meta?.has_next) { state.page += 1; loadProducts(); }
});

/* ================= login / logout ================= */

$('#login-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const errorBox = $('#login-error');
  hide(errorBox);
  setBusy($('#login-submit'), true);
  try {
    await api.login($('#login-email').value.trim(), $('#login-password').value);
    toast('Welcome back!');
    await showApp();
  } catch (err) {
    errorBox.textContent = err.message;
    show(errorBox);
  } finally {
    setBusy($('#login-submit'), false);
  }
});

$('#logout-btn').addEventListener('click', () => {
  auth.clear();
  toast('Signed out.');
  showLogin();
});

/* ================= product form modal ================= */

function clearFieldErrors() {
  hide($('#form-error'));
  document.querySelectorAll('.field-error').forEach((el) => { el.textContent = ''; });
}

function openProductModal(product = null) {
  state.editingId = product?.id ?? null;
  $('#modal-title').textContent = product ? `Edit “${product.name}”` : 'Add product';
  clearFieldErrors();

  const form = $('#product-form');
  form.reset();
  if (product) {
    form.name.value = product.name;
    form.sku.value = product.sku;
    form.category.value = product.category;
    form.price.value = product.price;
    form.stock.value = product.stock;
    form.description.value = product.description || '';
  }
  show($('#product-modal'));
  form.name.focus();
}

function closeProductModal() {
  hide($('#product-modal'));
  state.editingId = null;
}

$('#add-product-btn').addEventListener('click', () => openProductModal());
$('#modal-cancel').addEventListener('click', closeProductModal);
$('#product-modal').addEventListener('click', (e) => {
  if (e.target === e.currentTarget) closeProductModal();
});

$('#product-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  clearFieldErrors();
  const form = e.currentTarget;
  const payload = {
    name: form.name.value.trim(),
    sku: form.sku.value.trim(),
    category: form.category.value.trim(),
    price: form.price.value === '' ? null : Number(form.price.value),
    stock: form.stock.value === '' ? 0 : Number(form.stock.value),
    description: form.description.value.trim(),
  };

  setBusy($('#modal-save'), true);
  try {
    if (state.editingId === null) {
      await api.createProduct(payload);
      toast('Product created.');
    } else {
      await api.updateProduct(state.editingId, payload);
      toast('Product updated.');
    }
    closeProductModal();
    await Promise.all([loadProducts(), loadCategories()]);
  } catch (err) {
    if (err instanceof ApiError && Object.keys(err.details).length > 0) {
      for (const [field, message] of Object.entries(err.details)) {
        const el = document.querySelector(`.field-error[data-for="${field}"]`);
        if (el && message) el.textContent = message;
      }
      $('#form-error').textContent = 'Please fix the highlighted fields.';
    } else {
      $('#form-error').textContent = err.message;
    }
    show($('#form-error'));
  } finally {
    setBusy($('#modal-save'), false);
  }
});

/* ================= delete modal ================= */

function openDeleteModal(product) {
  state.deletingId = product.id;
  $('#delete-message').textContent =
    `Are you sure you want to delete “${product.name}” (${product.sku})? This cannot be undone.`;
  show($('#delete-modal'));
}

$('#delete-cancel').addEventListener('click', () => hide($('#delete-modal')));
$('#delete-modal').addEventListener('click', (e) => {
  if (e.target === e.currentTarget) hide($('#delete-modal'));
});

$('#delete-confirm').addEventListener('click', async () => {
  setBusy($('#delete-confirm'), true);
  try {
    await api.deleteProduct(state.deletingId);
    toast('Product deleted.');
    hide($('#delete-modal'));
    // If we deleted the last row of the last page, step back a page.
    if (state.products.length === 1 && state.page > 1) state.page -= 1;
    await loadProducts();
  } catch (err) {
    toast(err.message, 'error');
  } finally {
    setBusy($('#delete-confirm'), false);
  }
});

/* ================= row action delegation ================= */

$('#product-tbody').addEventListener('click', (e) => {
  const button = e.target.closest('button[data-action]');
  if (!button) return;
  const id = Number(button.closest('tr').dataset.id);
  const product = state.products.find((p) => p.id === id);
  if (!product) return;
  if (button.dataset.action === 'edit') openProductModal(product);
  if (button.dataset.action === 'delete') openDeleteModal(product);
});

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    closeProductModal();
    hide($('#delete-modal'));
  }
});

/* ================= boot ================= */

(async function boot() {
  if (!auth.token()) {
    showLogin();
    return;
  }
  try {
    await api.me(); // validate the stored token before showing the app
    await showApp();
  } catch {
    showLogin();
  }
})();
