(() => {
  'use strict';
  const config = window.BareBonesStorefront || {};
  const text = config.text || {};
  const apiBase = (config.apiUrl || '/api/v1/storefront').replace(/\/+$/, '');
  const apiUrl = (action, params = {}) => {
    const query = new URLSearchParams(params).toString();
    return `${apiBase}/${action}${query ? `?${query}` : ''}`;
  };

  function setTab(id) {
    document.querySelectorAll('[data-product-tab]').forEach((tab) => {
      const active = tab.dataset.productTab === id;
      tab.classList.toggle('active', active);
      tab.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    document.querySelectorAll('.tab-content').forEach((content) => {
      content.classList.toggle('active', content.id === `tab-${id}`);
    });
  }

  function escapeHtml(value) {
    return String(value).replace(/[&<>"]/g, (character) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'
    })[character]);
  }

  // Amounts are in the cart's currency, rounded like the server (stores.json decimals, e.g. 0 for CZK/HUF).
  function formatCents(cents) {
    const decimals = Number.isInteger(config.currencyDecimals) ? config.currencyDecimals : undefined;
    return (cents / 100).toLocaleString(config.locale || document.documentElement.lang, {
      style: 'currency',
      currency: String(config.currency).toUpperCase(),
      minimumFractionDigits: decimals,
      maximumFractionDigits: decimals
    });
  }

  // Goods after the discount: checkout prices free shipping thresholds and payment fees on this amount.
  function checkoutGoods(subtotal) {
    const discountElement = document.getElementById('checkout-discount');
    return Math.max(0, subtotal - (discountElement ? parseInt(discountElement.value || '0', 10) : 0));
  }

  // Each refresh supersedes the previous one: a response that arrives after a newer request started is dropped.
  let shippingRequest = 0;
  let paymentRequest = 0;

  async function fetchCheckoutJson(action, params) {
    const response = await fetch(apiUrl(action, params), {headers: {Accept: 'application/json'}});
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    return response.json();
  }

  // Integer cents carried by the rendered option (never re-parsed from the formatted, locale-dependent label).
  function optionCents(input) {
    const cents = input ? parseInt(input.dataset.priceCents || '', 10) : NaN;
    return Number.isFinite(cents) ? cents : 0;
  }

  function showCheckoutError(box, className, message) {
    box.innerHTML = `<div class="${className}" role="alert">${escapeHtml(message)}</div>`;
  }

  // The page's live region (storefront/shell): cart and wishlist results are announced, not only shown on a button.
  function announce(message) {
    const region = document.getElementById('storefront-flash');
    if (region) region.textContent = message || '';
  }

  async function addToCart(productId, quantity, variantId, button) {
    const original = button.textContent;
    if (button.dataset.variantPicker && !variantId) {
      button.textContent = text.selectVariant;
      setTimeout(() => { button.textContent = original; }, 1600);
      return;
    }
    button.disabled = true;
    const form = new FormData();
    form.append('cart_action', 'add');
    form.append('product_id', productId);
    form.append('quantity', quantity || 1);
    if (variantId) form.append('variant_id', variantId);
    form.append('csrf_token', config.csrfToken || '');
    try {
      const response = await fetch(config.currentUrl || '', {
        method: 'POST', body: form, headers: {'X-Requested-With': 'XMLHttpRequest'}
      });
      const result = await response.json();
      if (!response.ok || !result.ok) {
        button.textContent = text[result.error] || text.cartError;
        announce(button.textContent);
        return;
      }
      // Above the stock or the per-line maximum the cart keeps less than asked: say how many it holds.
      button.textContent = result.clamped
        ? String(text.quantityClamped || '').replace('{count}', String(result.quantity))
        : text.added;
      announce(result.clamped ? button.textContent : '');
      const badge = document.querySelector('.cart-badge');
      if (badge) badge.textContent = result.count;
      else {
        const icon = document.querySelector('.cart-icon');
        if (icon) {
          const nextBadge = document.createElement('span');
          nextBadge.className = 'cart-badge';
          nextBadge.textContent = result.count;
          icon.appendChild(nextBadge);
        }
      }
    } catch {
      button.textContent = text.cartError;
      announce(text.cartError);
    } finally {
      setTimeout(() => { button.disabled = false; button.textContent = original; }, 1600);
    }
  }

  function bindReviewForm() {
    const form = document.querySelector('[data-review-form]');
    if (!form) return;
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const button = form.querySelector('[type="submit"]');
      const message = form.querySelector('[data-review-msg]');
      if (button) button.disabled = true;
      const data = Object.fromEntries(new FormData(form));
      const csrfToken = String(data.csrf_token || '');
      delete data.csrf_token;
      data.product_id = Number(data.product_id);
      data.rating = Number(data.rating);
      try {
        const response = await fetch(form.action, {
          method: 'POST',
          headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken},
          body: JSON.stringify(data)
        });
        const result = await response.json();
        if (result.ok) form.reset();
        if (message) {
          const errors = {duplicate_review: form.dataset.duplicateLabel, rate_limited: form.dataset.rateLimitedLabel};
          const error = errors[result.error] || (response.status === 429 ? form.dataset.rateLimitedLabel : '') || form.dataset.errorLabel;
          message.hidden = false;
          message.textContent = result.ok ? form.dataset.successLabel : error;
        }
      } catch {
        if (message) {
          message.hidden = false;
          message.textContent = form.dataset.errorLabel;
        }
      } finally {
        if (button) button.disabled = false;
      }
    });
  }

  async function refreshCheckoutPayments(shippingMethod) {
    const country = document.getElementById('checkout-country');
    const box = document.getElementById('payment-options');
    const subtotalElement = document.getElementById('checkout-subtotal');
    if (!country || !box || !subtotalElement) return;
    const request = ++paymentRequest;
    const subtotal = parseInt(subtotalElement.value || '0', 10);
    const checkedShipping = document.querySelector('input[name="shipping_method"]:checked');
    const selectedShipping = shippingMethod || (checkedShipping ? checkedShipping.value : '');
    const shippingPrice = optionCents(checkedShipping);
    if (!selectedShipping) {
      box.innerHTML = `<div class="payment-empty">${escapeHtml(text.selectShipping)}</div>`;
      updateCheckoutTotal(shippingPrice, 0, subtotal);
      return;
    }
    const previous = box.querySelector('input[name="payment"]:checked');
    const previousCode = previous ? previous.value : '';
    box.innerHTML = `<div class="payment-loading" role="status">${escapeHtml(text.paymentLoading)}</div>`;
    const params = {
      country: country.value, currency: config.currency,
      amount: String(checkoutGoods(subtotal) + shippingPrice), shipping_method: selectedShipping, vat_id: checkoutVatId()
    };
    let result;
    try {
      result = await fetchCheckoutJson('payment_methods', params);
    } catch {
      if (request === paymentRequest) showCheckoutError(box, 'payment-error', text.paymentError || text.paymentUnavailable);
      return;
    }
    if (request !== paymentRequest) return;
    const methods = Array.isArray(result.methods) ? result.methods : [];
    if (!methods.length) {
      box.innerHTML = `<div class="payment-empty">${escapeHtml(text.paymentUnavailable)}</div>`;
      updateCheckoutTotal(shippingPrice, 0, subtotal);
      return;
    }
    box.innerHTML = '';
    const selectedIndex = Math.max(0, methods.findIndex((method) => (method.code || '') === previousCode));
    methods.forEach((method, index) => {
      const label = document.createElement('label');
      label.className = 'payment-option';
      const fee = parseInt(method.fee || 0, 10);
      label.innerHTML = `<span><input type="radio" name="payment" value="${escapeHtml(method.code || '')}" ${index === selectedIndex ? 'checked' : ''}${method.requires_online ? ' data-online' : ''}> ${escapeHtml(method.label || method.code || '')}<small>${escapeHtml(method.description || method.provider || '')}</small></span><strong>${fee === 0 ? escapeHtml(text.free) : formatCents(fee)}</strong>`;
      label.querySelector('input').addEventListener('change', () => updateCheckoutTotal(shippingPrice, fee, subtotal));
      box.appendChild(label);
    });
    updateCheckoutTotal(shippingPrice, parseInt(methods[selectedIndex].fee || 0, 10), subtotal);
  }

  // Buyer VAT ID the order would use: the checkout takes it only together with a company name.
  function checkoutVatId() {
    const vatId = document.getElementById('checkout-vat-id');
    const company = document.getElementById('checkout-company');
    return vatId && (!company || company.value.trim() !== '') ? vatId.value.trim() : '';
  }

  // Another VAT scenario (export, reverse charge) re-prices the whole order: let the server re-render it.
  function repriceCheckout(scenario) {
    const subtotalElement = document.getElementById('checkout-subtotal');
    const rendered = subtotalElement ? subtotalElement.dataset.vatScenario : '';
    if (!rendered || !scenario || rendered === scenario) return false;
    const button = document.querySelector('form[data-checkout-form] [name="checkout_refresh"]');
    if (!button) return false;
    button.form.requestSubmit(button);
    return true;
  }

  async function refreshCheckoutShipping(userChange) {
    const country = document.getElementById('checkout-country');
    const box = document.getElementById('shipping-options');
    const summaryShipping = document.getElementById('summary-shipping');
    const subtotalElement = document.getElementById('checkout-subtotal');
    if (!country || !box || !summaryShipping || !subtotalElement) return;
    const request = ++shippingRequest;
    paymentRequest++; // payment options of the previous shipping choice are stale as well
    const subtotal = parseInt(subtotalElement.value || '0', 10);
    // The rendered options stay until new rates arrive: a failed request only adds a notice above them.
    box.querySelectorAll('.shipping-loading, .shipping-error').forEach((node) => node.remove());
    box.insertAdjacentHTML('afterbegin', `<div class="shipping-loading" role="status">${escapeHtml(text.shippingLoading)}</div>`);
    const params = {country: country.value, subtotal: String(checkoutGoods(subtotal)), vat_id: checkoutVatId()};
    let result;
    try {
      result = await fetchCheckoutJson('shipping_rates', params);
    } catch {
      if (request !== shippingRequest) return;
      box.querySelectorAll('.shipping-loading').forEach((node) => node.remove());
      box.insertAdjacentHTML('afterbegin', `<div class="shipping-error shipping-notice" role="alert">${escapeHtml(text.shippingError || text.shippingUnavailable)}</div>`);
      return;
    }
    if (request !== shippingRequest) return;
    if (userChange === true && repriceCheckout(result.vat_scenario)) return;
    const methods = Array.isArray(result.methods) ? result.methods : [];
    const freeHint = document.getElementById('free-shipping-hint');
    if (freeHint) {
      freeHint.textContent = result.free_shipping_hint || '';
      freeHint.hidden = !result.free_shipping_hint;
    }
    if (!methods.length) {
      box.innerHTML = `<div class="shipping-empty">${escapeHtml(text.shippingUnavailable)}</div>`;
      summaryShipping.textContent = '—';
      await refreshCheckoutPayments('');
      return;
    }
    // Keep the choice on screen when the rates arrive — the server-rendered one (e.g. after applying a coupon) or one
    // the shopper picked while they were loading; fall back to the first rate.
    const previous = box.querySelector('input[name="shipping_method"]:checked');
    const previousCode = previous ? previous.value : '';
    box.innerHTML = '';
    const selectedIndex = Math.max(0, methods.findIndex((method) => (method.method_code || method.key || '') === previousCode));
    methods.forEach((method, index) => {
      const label = document.createElement('label');
      label.className = 'shipping-option';
      const methodCode = method.method_code || method.key || '';
      const price = parseInt(method.price || 0, 10);
      label.innerHTML = `<span><input type="radio" name="shipping_method" value="${escapeHtml(methodCode)}" data-price-cents="${price}" ${index === selectedIndex ? 'checked' : ''}> ${escapeHtml(method.label || methodCode)}<small>${escapeHtml(method.delivery_label || '')}</small></span><strong>${formatCents(price)}</strong>`;
      label.querySelector('input').addEventListener('change', () => refreshCheckoutPayments(methodCode));
      box.appendChild(label);
    });
    await refreshCheckoutPayments(methods[selectedIndex].method_code || methods[selectedIndex].key || '');
  }

  function updateCheckoutTotal(shipping, paymentFee, subtotal) {
    document.getElementById('summary-shipping').textContent = formatCents(shipping);
    const payment = document.getElementById('summary-payment');
    if (payment) payment.textContent = paymentFee === 0 ? text.free : formatCents(paymentFee);
    const discountElement = document.getElementById('checkout-discount');
    const discount = discountElement ? parseInt(discountElement.value || '0', 10) : 0;
    document.getElementById('summary-total').textContent = formatCents(subtotal - discount + shipping + paymentFee);
  }

  function setConsentCookie(categories) {
    const base = config.baseUrl || '';
    document.cookie = `cc_v1=${encodeURIComponent(JSON.stringify(categories))};path=${base || '/'};max-age=31536000;SameSite=Lax`;
  }

  function saveConsent(categories, method) {
    const banner = document.getElementById('cc-banner');
    setConsentCookie(categories);
    const form = new FormData();
    form.append('necessary', categories.necessary ? 1 : 0);
    form.append('analytics', categories.analytics ? 1 : 0);
    form.append('marketing', categories.marketing ? 1 : 0);
    form.append('method', method);
    form.append('csrf_token', config.csrfToken || '');
    fetch(apiUrl('cookie_consent'), {method: 'POST', body: form}).catch(() => {});
    if (banner) banner.remove();
  }

  function addFromButton(cartButton) {
    const quantityInput = cartButton.dataset.quantityInput ? document.getElementById(cartButton.dataset.quantityInput) : null;
    const variant = cartButton.dataset.variantPicker
      ? document.querySelector(`[name="variant-${cartButton.dataset.variantPicker}"]:checked`)
      : null;
    addToCart(cartButton.dataset.productId, quantityInput ? quantityInput.value : cartButton.dataset.quantity, variant ? variant.value : '', cartButton);
  }

  // Add-to-cart buttons submit a real form (works without JS); with JS the submit stays on the page.
  document.addEventListener('submit', (event) => {
    const cartButton = event.target.querySelector('[data-storefront-action="cart-add"]');
    if (!cartButton) return;
    event.preventDefault();
    addFromButton(cartButton);
  });

  document.addEventListener('click', (event) => {
    // A theme override that still renders a formless type="button" add-to-cart button keeps working.
    const cartButton = event.target.closest('[data-storefront-action="cart-add"]');
    if (cartButton && !cartButton.form) {
      addFromButton(cartButton);
      return;
    }
    const tab = event.target.closest('[data-product-tab]');
    if (tab) setTab(tab.dataset.productTab);
  });

  // data-submit-on-change (WCAG 3.2.2): a select submits on a pointer choice, not on every arrow key —
  // keyboard users press Enter or the visible submit button; a quantity field submits once typing pauses.
  let keyboardSelect = null;
  let submitTimer = 0;
  document.addEventListener('keydown', (event) => {
    const select = event.target.closest && event.target.closest('select[data-submit-on-change]');
    if (!select) return;
    if (event.key === 'Enter' && select.form) {
      event.preventDefault();
      select.form.requestSubmit();
    } else if (event.key !== 'Tab') {
      keyboardSelect = select;
    }
  });
  document.addEventListener('pointerdown', () => { keyboardSelect = null; });
  document.addEventListener('input', (event) => {
    if (event.target.closest('input[data-submit-on-change]')) clearTimeout(submitTimer);
  });

  document.addEventListener('change', (event) => {
    const variant = event.target.closest('[data-variant-picker] input[type="radio"]');
    if (variant) {
      const detail = variant.closest('.product-detail');
      const price = detail ? detail.querySelector('[data-product-price]') : null;
      const availability = detail ? detail.querySelector('[data-product-availability]') : null;
      if (price) price.textContent = variant.dataset.price || '';
      if (availability) availability.textContent = variant.dataset.availability || '';
    }
    const control = event.target.closest('[data-submit-on-change]');
    if (!control || !control.form) return;
    clearTimeout(submitTimer);
    if (control.tagName !== 'SELECT') submitTimer = setTimeout(() => control.form.requestSubmit(), 800);
    else if (keyboardSelect !== control) control.form.requestSubmit();
  });

  document.addEventListener('submit', (event) => {
    const form = event.target.closest('form[data-confirm]');
    if (form && !window.confirm(form.dataset.confirm)) event.preventDefault();
  });

  // One order per click: lock the checkout button while the POST (and the gateway redirect) runs.
  document.addEventListener('submit', (event) => {
    const form = event.target.closest('form[data-checkout-form]');
    if (!form || event.defaultPrevented) return;
    // Applying a coupon or a saved address only refills the form; it must not lock the order button.
    if (event.submitter && ['coupon_action', 'address_action', 'checkout_refresh'].includes(event.submitter.name)) return;
    const button = form.querySelector('button.btn-checkout');
    if (!button) return;
    if (form.dataset.submitting === '1') {
      event.preventDefault();
      return;
    }
    form.dataset.submitting = '1';
    if (!button.dataset.label) button.dataset.label = button.textContent;
    const online = form.querySelector('input[name="payment"]:checked[data-online]');
    button.textContent = (online ? form.dataset.redirectingLabel : form.dataset.submittingLabel) || button.textContent;
    button.setAttribute('aria-busy', 'true');
    setTimeout(() => { button.disabled = true; }, 0);
  });

  // Back from the gateway restores the page from bfcache: unlock the button again.
  window.addEventListener('pageshow', () => {
    document.querySelectorAll('form[data-checkout-form]').forEach((form) => {
      const button = form.querySelector('button.btn-checkout');
      delete form.dataset.submitting;
      if (!button) return;
      button.disabled = false;
      button.removeAttribute('aria-busy');
      if (button.dataset.label) button.textContent = button.dataset.label;
    });
  });

  // Subcategory menus are <details> (tap/keyboard/no-JS). JS adds hover on mouse devices, one open menu,
  // Escape to close back to the toggle and closing on an outside click.
  function bindCategoryMenus() {
    const menus = [...document.querySelectorAll('.header-nav .nav-sub')];
    if (!menus.length) return;
    const closeOthers = (keep) => menus.forEach((menu) => { if (menu !== keep) menu.open = false; });
    const hover = window.matchMedia('(hover: hover) and (pointer: fine)');
    menus.forEach((menu) => {
      const item = menu.closest('.nav-item');
      menu.addEventListener('toggle', () => { if (menu.open) closeOthers(menu); });
      // A menu opened by hovering stays open when the caret is then clicked; it closes when the mouse leaves.
      item.addEventListener('mouseenter', () => {
        if (!hover.matches || menu.open) return;
        menu.open = true;
        menu.dataset.hoverOpen = '1';
      });
      item.addEventListener('mouseleave', () => {
        if (menu.dataset.hoverOpen !== '1') return;
        delete menu.dataset.hoverOpen;
        if (!menu.contains(document.activeElement)) menu.open = false;
      });
      menu.querySelector('summary').addEventListener('click', (event) => {
        if (menu.dataset.hoverOpen !== '1') return;
        event.preventDefault();
        delete menu.dataset.hoverOpen;
      });
      menu.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || !menu.open) return;
        menu.open = false;
        menu.querySelector('summary').focus();
      });
    });
    document.addEventListener('click', (event) => {
      if (!event.target.closest('.header-nav .nav-item')) closeOthers(null);
    });
  }

  // Search suggestions as an ARIA combobox: arrows move through the list, Enter opens the highlighted product,
  // Escape closes. Built with textContent and same-origin links only; without JS the form submits as before.
  function bindSearchSuggest(input, index) {
    const form = input.form;
    if (!form) return;
    const list = document.createElement('ul');
    list.id = `search-suggest-${index}`;
    list.className = 'search-suggestions';
    list.setAttribute('role', 'listbox');
    list.setAttribute('aria-label', input.dataset.searchSuggest || '');
    list.hidden = true;
    form.classList.add('search-suggest-host');
    input.insertAdjacentElement('afterend', list);
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-controls', list.id);
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('autocomplete', 'off');
    let timer = 0;
    let request = 0;
    let active = -1;
    const options = () => [...list.querySelectorAll('[role="option"]')];
    const close = () => {
      list.hidden = true;
      list.replaceChildren();
      active = -1;
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
    };
    const highlight = (next) => {
      const items = options();
      if (!items.length) return;
      active = (next + items.length) % items.length;
      items.forEach((item, position) => item.setAttribute('aria-selected', position === active ? 'true' : 'false'));
      input.setAttribute('aria-activedescendant', items[active].id);
    };
    const show = (suggestions) => {
      list.replaceChildren();
      active = -1;
      suggestions.forEach((suggestion) => {
        let url;
        try { url = new URL(String(suggestion.url || ''), window.location.href); } catch { return; }
        if (url.origin !== window.location.origin || !suggestion.name) return;
        // An option must not contain a link (nested-interactive); it carries its target instead.
        const option = document.createElement('li');
        option.id = `${list.id}-${list.children.length}`;
        option.className = 'search-suggestion';
        option.setAttribute('role', 'option');
        option.setAttribute('aria-selected', 'false');
        option.dataset.href = url.href;
        option.textContent = String(suggestion.name);
        list.appendChild(option);
      });
      list.hidden = list.children.length === 0;
      input.setAttribute('aria-expanded', list.hidden ? 'false' : 'true');
      input.removeAttribute('aria-activedescendant');
    };
    input.addEventListener('input', () => {
      clearTimeout(timer);
      const query = input.value.trim();
      if (query.length < 2) { request++; close(); return; }
      timer = setTimeout(async () => {
        const mine = ++request;
        try {
          const response = await fetch(apiUrl('search_suggest', {q: query}), {headers: {Accept: 'application/json'}});
          const result = response.ok ? await response.json() : [];
          if (mine === request) show(Array.isArray(result) ? result : []);
        } catch {
          if (mine === request) close();
        }
      }, 250);
    });
    input.addEventListener('keydown', (event) => {
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        if (list.hidden) return;
        event.preventDefault();
        highlight(active + (event.key === 'ArrowDown' ? 1 : -1));
      } else if (event.key === 'Enter' && !list.hidden && active >= 0) {
        event.preventDefault();
        window.location.assign(options()[active].dataset.href);
      } else if (event.key === 'Escape' && !list.hidden) {
        event.preventDefault();
        close();
      }
    });
    // Close after focus leaves; mousedown on an option keeps focus so its click still lands.
    list.addEventListener('mousedown', (event) => event.preventDefault());
    list.addEventListener('click', (event) => {
      const option = event.target.closest('[role="option"]');
      if (option) window.location.assign(option.dataset.href);
    });
    input.addEventListener('blur', close);
  }

  document.addEventListener('DOMContentLoaded', () => {
    bindReviewForm();
    bindCategoryMenus();
    document.querySelectorAll('input[data-search-suggest]').forEach(bindSearchSuggest);
    const country = document.getElementById('checkout-country');
    if (country) country.addEventListener('change', () => refreshCheckoutShipping(true));
    ['checkout-vat-id', 'checkout-company'].forEach((id) => {
      const field = document.getElementById(id);
      if (field && country) field.addEventListener('change', () => refreshCheckoutShipping(true));
    });
    if (document.getElementById('shipping-options')) refreshCheckoutShipping();

    const banner = document.getElementById('cc-banner');
    if (!banner) return;
    document.getElementById('cc-accept').addEventListener('click', () => saveConsent({necessary:true,analytics:true,marketing:true}, 'banner_accept_all'));
    document.getElementById('cc-reject').addEventListener('click', () => saveConsent({necessary:true,analytics:false,marketing:false}, 'banner_reject_all'));
    document.getElementById('cc-prefs-toggle').addEventListener('click', () => {
      banner.classList.add('show-prefs');
      document.getElementById('cc-accept').hidden = true;
      document.getElementById('cc-reject').hidden = true;
      document.getElementById('cc-save').hidden = false;
    });
    document.getElementById('cc-save').addEventListener('click', () => saveConsent({
      necessary: true,
      analytics: document.getElementById('cc-ana').checked,
      marketing: document.getElementById('cc-mkt').checked
    }, 'preferences'));
  });
})();
