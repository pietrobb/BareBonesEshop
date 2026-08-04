(() => {
  'use strict';
  const config = window.BareBonesStorefront || {};
  const text = config.text || {};
  const apiUrl = config.apiUrl || 'api.php';

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

  function formatCents(cents) {
    return (cents / 100).toLocaleString(config.locale || document.documentElement.lang, {
      style: 'currency',
      currency: String(config.currency).toUpperCase()
    });
  }

  function parseMoney(value) {
    if (String(value).includes(text.free)) return 0;
    const normalized = String(value).replace(/[^0-9,.-]/g, '').replace(',', '.');
    return Math.round((parseFloat(normalized) || 0) * 100);
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
        return;
      }
      button.textContent = text.added;
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
    } catch (error) {
      button.textContent = text.cartError;
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
          message.hidden = false;
          message.textContent = result.ok ? form.dataset.successLabel : form.dataset.errorLabel;
        }
      } catch (error) {
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
    const subtotal = parseInt(subtotalElement.value || '0', 10);
    const checkedShipping = document.querySelector('input[name="shipping_method"]:checked');
    const selectedShipping = shippingMethod || (checkedShipping ? checkedShipping.value : '');
    const shippingLabel = checkedShipping ? checkedShipping.closest('label') : null;
    const shippingPrice = shippingLabel ? parseMoney(shippingLabel.querySelector('strong').textContent) : 0;
    if (!selectedShipping) {
      box.innerHTML = `<div class="payment-empty">${escapeHtml(text.selectShipping)}</div>`;
      updateCheckoutTotal(shippingPrice, 0, subtotal);
      return;
    }
    box.innerHTML = `<div class="payment-loading">${escapeHtml(text.paymentLoading)}</div>`;
    const params = new URLSearchParams({
      action: 'payment_methods', country: country.value, currency: config.currency,
      amount: String(subtotal + shippingPrice), shipping_method: selectedShipping
    });
    const response = await fetch(`${apiUrl}?${params}`);
    const result = await response.json();
    const methods = result.methods || [];
    if (!methods.length) {
      box.innerHTML = `<div class="payment-empty">${escapeHtml(text.paymentUnavailable)}</div>`;
      updateCheckoutTotal(shippingPrice, 0, subtotal);
      return;
    }
    box.innerHTML = '';
    methods.forEach((method, index) => {
      const label = document.createElement('label');
      label.className = 'payment-option';
      const fee = parseInt(method.fee || 0, 10);
      label.innerHTML = `<span><input type="radio" name="payment" value="${escapeHtml(method.code || '')}" ${index === 0 ? 'checked' : ''}> ${escapeHtml(method.label || method.code || '')}<small>${escapeHtml(method.description || method.provider || '')}</small></span><strong>${fee === 0 ? escapeHtml(text.free) : formatCents(fee)}</strong>`;
      label.querySelector('input').addEventListener('change', () => updateCheckoutTotal(shippingPrice, fee, subtotal));
      box.appendChild(label);
    });
    updateCheckoutTotal(shippingPrice, parseInt(methods[0].fee || 0, 10), subtotal);
  }

  async function refreshCheckoutShipping() {
    const country = document.getElementById('checkout-country');
    const box = document.getElementById('shipping-options');
    const summaryShipping = document.getElementById('summary-shipping');
    const subtotalElement = document.getElementById('checkout-subtotal');
    if (!country || !box || !summaryShipping || !subtotalElement) return;
    const subtotal = parseInt(subtotalElement.value || '0', 10);
    box.innerHTML = `<div class="shipping-loading">${escapeHtml(text.shippingLoading)}</div>`;
    const params = new URLSearchParams({action: 'shipping_rates', country: country.value, subtotal: String(subtotal)});
    const response = await fetch(`${apiUrl}?${params}`);
    const result = await response.json();
    const methods = result.methods || [];
    if (!methods.length) {
      box.innerHTML = `<div class="shipping-empty">${escapeHtml(text.shippingUnavailable)}</div>`;
      summaryShipping.textContent = '—';
      await refreshCheckoutPayments('');
      return;
    }
    box.innerHTML = '';
    methods.forEach((method, index) => {
      const label = document.createElement('label');
      label.className = 'shipping-option';
      const methodCode = method.method_code || method.key || '';
      const price = parseInt(method.price || 0, 10);
      label.innerHTML = `<span><input type="radio" name="shipping_method" value="${escapeHtml(methodCode)}" ${index === 0 ? 'checked' : ''}> ${escapeHtml(method.label || methodCode)}<small>${escapeHtml(method.delivery_days || '')}</small></span><strong>${formatCents(price)}</strong>`;
      label.querySelector('input').addEventListener('change', () => refreshCheckoutPayments(methodCode));
      box.appendChild(label);
    });
    await refreshCheckoutPayments(methods[0].method_code || methods[0].key || '');
  }

  function updateCheckoutTotal(shipping, paymentFee, subtotal) {
    document.getElementById('summary-shipping').textContent = formatCents(shipping);
    const payment = document.getElementById('summary-payment');
    if (payment) payment.textContent = paymentFee === 0 ? text.free : formatCents(paymentFee);
    document.getElementById('summary-total').textContent = formatCents(subtotal + shipping + paymentFee);
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
    fetch(`${apiUrl}?action=cookie_consent`, {method: 'POST', body: form}).catch(() => {});
    if (banner) banner.remove();
  }

  document.addEventListener('click', (event) => {
    const cartButton = event.target.closest('[data-storefront-action="cart-add"]');
    if (cartButton) {
      const quantityInput = cartButton.dataset.quantityInput ? document.getElementById(cartButton.dataset.quantityInput) : null;
      const variant = cartButton.dataset.variantPicker
        ? document.querySelector(`[name="variant-${cartButton.dataset.variantPicker}"]:checked`)
        : null;
      addToCart(cartButton.dataset.productId, quantityInput ? quantityInput.value : cartButton.dataset.quantity, variant ? variant.value : '', cartButton);
      return;
    }
    const tab = event.target.closest('[data-product-tab]');
    if (tab) setTab(tab.dataset.productTab);
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
    if (control && control.form) control.form.requestSubmit();
  });

  document.addEventListener('submit', (event) => {
    const form = event.target.closest('form[data-confirm]');
    if (form && !window.confirm(form.dataset.confirm)) event.preventDefault();
  });

  document.addEventListener('DOMContentLoaded', () => {
    bindReviewForm();
    const country = document.getElementById('checkout-country');
    if (country) country.addEventListener('change', refreshCheckoutShipping);
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
