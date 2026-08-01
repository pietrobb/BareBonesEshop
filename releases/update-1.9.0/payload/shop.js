/**
 * BareBones Shop — Frontend JavaScript
 * Zero dependencies, pure DOM manipulation.
 * State: category, page, sort, filters, cart.
 * localStorage cart mirror for session recovery.
 */
'use strict';

const Shop = {
    state: {
        category: null,
        page: 1,
        sort: 'price_asc',
        filters: {},
        priceMin: null,
        priceMax: null,
        cart: { items: [], count: 0, subtotal: 0 },
    },

    // ============================================================
    // Init
    // ============================================================
    async init() {
        this.csrfToken = window.BBS?.csrfToken || '';
        this.lang = window.BBS?.lang || 'sk';

        // Restore cart from localStorage if session expired
        await CartStore.restore();
        this.updateCartBadge();

        // Bind global events
        this.bindCartActions();
        this.bindFilterActions();
        this.bindSortActions();
        this.bindTabActions();
        this.bindGalleryActions();
        this.bindSearchActions();
        this.bindCheckoutForm();
        this.bindReviewForm();
        this.bindWishlist();
    },

    // ============================================================
    // Products — Loading & Rendering
    // ============================================================
    async loadProducts() {
        const params = new URLSearchParams({
            action: 'products',
            cat: this.state.category || '',
            page: this.state.page,
            sort: this.state.sort,
        });
        if (Object.keys(this.state.filters).length > 0) {
            params.set('filters', JSON.stringify(this.state.filters));
        }
        if (this.state.priceMin !== null) params.set('price_min', this.state.priceMin);
        if (this.state.priceMax !== null) params.set('price_max', this.state.priceMax);

        try {
            const res = await fetch('api.php?' + params);
            const data = await res.json();
            this.renderProducts(data.products || []);
            this.renderPagination(data.total, data.page, data.per_page);
        } catch (e) {
            console.error('Failed to load products:', e);
        }
    },

    renderProducts(products) {
        const grid = document.querySelector('[data-product-grid]');
        if (!grid) return;

        if (products.length === 0) {
            grid.innerHTML = '<p class="no-products">' + (window.BBS?.t?.no_products || 'No products found.') + '</p>';
            return;
        }

        grid.innerHTML = products.map(p => this.productCardHTML(p)).join('');
    },

    productCardHTML(p) {
        let imgSrc = '';
        if (p.image) {
            imgSrc = (p.image.startsWith('http') || p.image.startsWith('/'))
                ? this.esc(p.image)
                : `/uploads/medium/${this.esc(p.image)}`;
        }
        const webpSrc = imgSrc ? imgSrc.replace(/\.(jpg|jpeg|png|gif)$/i, '.webp') : '';
        const image = imgSrc
            ? `<picture>
                <source srcset="${webpSrc}" type="image/webp">
                <img src="${imgSrc}" alt="${this.esc(p.name)}" loading="lazy">
               </picture>`
            : '';
        const badge = p.compare_price ? `<span class="product-card__badge">-${p.discount_percent}%</span>` : '';
        const oldPrice = p.compare_price ? `<span class="price--old">${this.esc(p.compare_price_formatted)}</span>` : '';
        const cartBtn = p.stock > 0
            ? `<button class="product-card__cart" data-action="cart-add" data-product="${p.id}">+</button>`
            : '';

        return `<article class="product-card" data-id="${p.id}">
            <a href="${this.esc(p.url)}" class="product-card__link">
                <div class="product-card__image">${image}${badge}</div>
                <div class="product-card__body">
                    <h3 class="product-card__name">${this.esc(p.name)}</h3>
                    <div class="product-card__price">${oldPrice}<span class="price--current">${this.esc(p.price_formatted)}</span></div>
                </div>
            </a>
            ${cartBtn}
        </article>`;
    },

    renderPagination(total, page, perPage) {
        const container = document.querySelector('[data-pagination]');
        if (!container) return;

        const pages = Math.ceil(total / perPage);
        if (pages <= 1) { container.innerHTML = ''; return; }

        let html = '<nav class="pagination">';
        if (page > 1) {
            html += `<button class="pagination__btn" data-page="${page - 1}">&laquo;</button>`;
        }
        for (let i = 1; i <= pages; i++) {
            const active = i === page ? ' is-active' : '';
            html += `<button class="pagination__btn${active}" data-page="${i}">${i}</button>`;
        }
        if (page < pages) {
            html += `<button class="pagination__btn" data-page="${page + 1}">&raquo;</button>`;
        }
        html += '</nav>';
        container.innerHTML = html;

        container.querySelectorAll('[data-page]').forEach(btn => {
            btn.addEventListener('click', () => {
                this.state.page = parseInt(btn.dataset.page);
                this.loadProducts();
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        });
    },

    // ============================================================
    // Cart
    // ============================================================
    bindCartActions() {
        document.addEventListener('click', (e) => {
            const addBtn = e.target.closest('[data-action="cart-add"]');
            if (addBtn) {
                e.preventDefault();
                const productId = parseInt(addBtn.dataset.product);
                const qtyInput = addBtn.closest('.product-detail__actions, .product-info__actions')?.querySelector('[data-qty]');
                const qty = qtyInput ? parseInt(qtyInput.value) || 1 : 1;
                const variantInput = document.querySelector('[name="variant"]:checked');
                const variantId = variantInput ? parseInt(variantInput.value) : null;
                this.cartAdd(productId, variantId, qty);
            }

            const removeBtn = e.target.closest('[data-action="cart-remove"]');
            if (removeBtn) {
                e.preventDefault();
                this.cartUpdate(removeBtn.dataset.key, 0);
            }
        });

        document.addEventListener('change', (e) => {
            const qtyInput = e.target.closest('[data-action="cart-qty"]');
            if (qtyInput) {
                this.cartUpdate(qtyInput.dataset.key, parseInt(qtyInput.value) || 0);
            }
        });
    },

    async cartAdd(productId, variantId, qty) {
        try {
            const res = await fetch('api.php?action=cart_add', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfToken },
                body: JSON.stringify({ product_id: productId, variant_id: variantId, qty }),
            });
            const data = await res.json();
            if (data.cart) {
                this.state.cart = data.cart;
                CartStore.save(data.cart);
                this.updateCartBadge();
                this.showNotification('Pridané do košíka');
            }
        } catch (e) {
            console.error('Cart add failed:', e);
        }
    },

    async cartUpdate(key, qty) {
        try {
            const res = await fetch('api.php?action=cart_update', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfToken },
                body: JSON.stringify({ key, qty }),
            });
            const data = await res.json();
            if (data.cart) {
                this.state.cart = data.cart;
                CartStore.save(data.cart);
                this.updateCartBadge();
                this.renderCartItems();
            }
        } catch (e) {
            console.error('Cart update failed:', e);
        }
    },

    updateCartBadge() {
        const badge = document.querySelector('[data-cart-count]');
        if (badge) {
            badge.textContent = this.state.cart.count || 0;
            badge.style.display = this.state.cart.count > 0 ? '' : 'none';
        }
    },

    renderCartItems() {
        const container = document.querySelector('[data-cart-items]');
        if (!container) return;
        // Re-render by reloading the enriched cart via AJAX
        location.reload();
    },

    // ============================================================
    // Filters
    // ============================================================
    bindFilterActions() {
        document.addEventListener('change', (e) => {
            const checkbox = e.target.closest('.filter-option input[type="checkbox"]');
            if (checkbox) {
                this.collectFilters();
                this.state.page = 1;
                this.loadProducts();
            }
        });

        document.addEventListener('click', (e) => {
            const clearBtn = e.target.closest('[data-clear-filters]');
            if (clearBtn) {
                e.preventDefault();
                this.state.filters = {};
                this.state.priceMin = null;
                this.state.priceMax = null;
                document.querySelectorAll('.filter-option input:checked').forEach(cb => cb.checked = false);
                document.querySelectorAll('.price-range input').forEach(inp => inp.value = '');
                this.state.page = 1;
                this.loadProducts();
            }

            const removeTag = e.target.closest('[data-remove]');
            if (removeTag) {
                e.preventDefault();
                const [attr, value] = removeTag.dataset.remove.split(':');
                if (this.state.filters[attr]) {
                    this.state.filters[attr] = this.state.filters[attr].filter(v => v !== value);
                    if (this.state.filters[attr].length === 0) delete this.state.filters[attr];
                }
                // Uncheck matching checkbox
                const cb = document.querySelector(`.filter-option input[name="${attr}"][value="${value}"]`);
                if (cb) cb.checked = false;
                this.state.page = 1;
                this.loadProducts();
            }
        });

        // Price range — apply on blur/enter
        document.querySelectorAll('.price-range input').forEach(inp => {
            inp.addEventListener('change', () => {
                const minInput = document.querySelector('[name="price_min"]');
                const maxInput = document.querySelector('[name="price_max"]');
                this.state.priceMin = minInput?.value ? parseInt(minInput.value) * 100 : null;
                this.state.priceMax = maxInput?.value ? parseInt(maxInput.value) * 100 : null;
                this.state.page = 1;
                this.loadProducts();
            });
        });
    },

    collectFilters() {
        this.state.filters = {};
        document.querySelectorAll('.filter-option input[type="checkbox"]:checked').forEach(cb => {
            const name = cb.name;
            if (!this.state.filters[name]) this.state.filters[name] = [];
            this.state.filters[name].push(cb.value);
        });
    },

    // ============================================================
    // Sort
    // ============================================================
    bindSortActions() {
        document.addEventListener('change', (e) => {
            const select = e.target.closest('[data-sort]');
            if (select) {
                this.state.sort = select.value;
                this.state.page = 1;
                this.loadProducts();
            }
        });
    },

    // ============================================================
    // Tabs
    // ============================================================
    bindTabActions() {
        document.addEventListener('click', (e) => {
            const tab = e.target.closest('[data-tab]');
            if (!tab) return;
            const container = tab.closest('[data-tabs]');
            if (!container) return;

            container.querySelectorAll('[data-tab]').forEach(t => t.classList.remove('is-active'));
            container.querySelectorAll('[data-panel]').forEach(p => p.classList.remove('is-active'));
            tab.classList.add('is-active');
            const panel = container.querySelector(`[data-panel="${tab.dataset.tab}"]`);
            if (panel) panel.classList.add('is-active');
        });
    },

    // ============================================================
    // Gallery
    // ============================================================
    bindGalleryActions() {
        document.addEventListener('click', (e) => {
            const thumb = e.target.closest('[data-image]');
            if (!thumb) return;
            const gallery = thumb.closest('[data-gallery]');
            if (!gallery) return;

            gallery.querySelectorAll('.gallery__thumb').forEach(t => t.classList.remove('is-active'));
            thumb.classList.add('is-active');

            const hero = gallery.querySelector('.gallery__hero');
            if (hero) hero.src = thumb.dataset.image;
        });
    },

    // ============================================================
    // Search
    // ============================================================
    bindSearchActions() {
        const searchInput = document.querySelector('[data-search]');
        if (!searchInput) return;

        let timeout = null;
        searchInput.addEventListener('input', () => {
            clearTimeout(timeout);
            timeout = setTimeout(() => this.doSuggest(searchInput.value), 300);
        });

        searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                clearTimeout(timeout);
                window.location.href = '/?search=' + encodeURIComponent(searchInput.value);
            }
        });
    },

    async doSuggest(query) {
        const dropdown = document.querySelector('[data-search-results]');
        if (!dropdown) return;
        if (query.length < 2) { dropdown.innerHTML = ''; dropdown.style.display = 'none'; return; }

        try {
            const res = await fetch(`api.php?action=search_suggest&q=${encodeURIComponent(query)}`);
            const data = await res.json();
            if (data.length === 0) {
                dropdown.innerHTML = '';
                dropdown.style.display = 'none';
                return;
            }
            dropdown.innerHTML = data.map(s =>
                `<a href="/product/${this.esc(s.slug)}" class="search-suggestion">${this.esc(s.name)}</a>`
            ).join('');
            dropdown.style.display = 'block';
        } catch (e) {
            console.error('Suggest failed:', e);
        }
    },

    // ============================================================
    // Checkout
    // ============================================================
    async submitCheckout(formData) {
        try {
            const res = await fetch('api.php?action=checkout', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfToken },
                body: JSON.stringify(formData),
            });
            const data = await res.json();

            if (data.error) {
                this.showNotification(data.error);
                return data;
            }

            if (data.success) {
                CartStore.clear();
                this.state.cart = { items: [], count: 0, subtotal: 0 };
                this.updateCartBadge();

                // Redirect to payment gateway if URL provided
                if (data.payment_url) {
                    window.location.href = data.payment_url;
                    return data;
                }

                // COD / bank transfer — redirect to success page
                if (data.order_number) {
                    window.location.href = '/checkout/success?order=' + encodeURIComponent(data.order_number);
                    return data;
                }
            }

            return data;
        } catch (e) {
            console.error('Checkout failed:', e);
            this.showNotification('Chyba pri odosielaní objednávky');
            return { error: 'Network error' };
        }
    },

    bindCheckoutForm() {
        const form = document.querySelector('[data-checkout-form]');
        if (!form) return;

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = form.querySelector('[type="submit"]');
            if (btn) { btn.disabled = true; btn.textContent = 'Spracovávam...'; }

            const fd = new FormData(form);
            const data = {};
            fd.forEach((val, key) => { data[key] = val; });

            await this.submitCheckout(data);
            if (btn) { btn.disabled = false; btn.textContent = 'Objednať'; }
        });
    },

    // ============================================================
    // Product reviews
    // ============================================================
    bindReviewForm() {
        const form = document.querySelector('[data-review-form]');
        if (!form) return;

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = form.querySelector('[type="submit"]');
            const msg = form.querySelector('[data-review-msg]');
            if (btn) btn.disabled = true;

            const fd = new FormData(form);
            const data = { product_id: Number(form.dataset.productId) };
            fd.forEach((val, key) => { if (key !== 'csrf_token') data[key] = val; });

            try {
                const res = await fetch('api.php?action=review_submit', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfToken },
                    body: JSON.stringify(data),
                });
                const json = await res.json();
                if (json.ok) {
                    form.reset();
                    if (msg) { msg.hidden = false; msg.textContent = 'Ďakujeme! Recenzia čaká na schválenie.'; }
                } else if (msg) {
                    msg.hidden = false;
                    msg.textContent = 'Recenziu sa nepodarilo odoslať (' + this.esc(json.error || 'error') + ').';
                }
            } catch (err) {
                if (msg) { msg.hidden = false; msg.textContent = 'Chyba siete.'; }
            } finally {
                if (btn) btn.disabled = false;
            }
        });
    },

    // ============================================================
    // Wishlist (progressive enhancement over the no-JS POST forms)
    // ============================================================
    bindWishlist() {
        // Toggle button on the product page.
        document.querySelectorAll('[data-wishlist-toggle]').forEach((form) => {
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                const btn = form.querySelector('[data-wishlist-btn]') || form.querySelector('[type="submit"]');
                const pid = Number(form.dataset.productId);
                if (btn) btn.disabled = true;
                const json = await this.wishlistToggle(pid);
                if (json && json.ok && btn) {
                    btn.setAttribute('aria-pressed', json.in_list ? 'true' : 'false');
                    btn.textContent = json.in_list ? 'In wishlist ♥' : 'Add to wishlist ♡';
                    this.showNotification(json.in_list ? 'Pridané do obľúbených' : 'Odobraté z obľúbených');
                }
                if (btn) btn.disabled = false;
            });
        });

        // Remove forms on the wishlist page — drop the <li> on success.
        document.querySelectorAll('[data-wishlist-remove]').forEach((form) => {
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                const pid = Number(form.dataset.productId);
                const json = await this.wishlistToggle(pid);
                if (json && json.ok && !json.in_list) {
                    const li = form.closest('[data-product-id]');
                    if (li) li.remove();
                }
            });
        });
    },

    async wishlistToggle(productId) {
        try {
            const res = await fetch('api.php?action=wishlist_toggle', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfToken },
                body: JSON.stringify({ product_id: productId }),
            });
            return await res.json();
        } catch (err) {
            this.showNotification('Chyba siete.');
            return null;
        }
    },

    // ============================================================
    // Notifications
    // ============================================================
    showNotification(message) {
        const el = document.createElement('div');
        el.className = 'notification';
        el.textContent = message;
        document.body.appendChild(el);
        requestAnimationFrame(() => el.classList.add('is-visible'));
        setTimeout(() => {
            el.classList.remove('is-visible');
            setTimeout(() => el.remove(), 300);
        }, 2000);
    },

    // ============================================================
    // Helpers
    // ============================================================
    esc(s) {
        if (s == null) return '';
        const el = document.createElement('span');
        el.textContent = String(s);
        return el.innerHTML;
    },
};

// ============================================================
// localStorage Cart Mirror
// ============================================================
const CartStore = {
    KEY: 'bbs_cart',

    save(cart) {
        try {
            localStorage.setItem(this.KEY, JSON.stringify(cart));
        } catch (e) { /* localStorage full or blocked */ }
    },

    load() {
        try {
            return JSON.parse(localStorage.getItem(this.KEY)) || null;
        } catch { return null; }
    },

    clear() {
        localStorage.removeItem(this.KEY);
    },

    async restore() {
        try {
            const serverCart = await fetch('api.php?action=cart').then(r => r.json());
            if (serverCart.count > 0) {
                this.save(serverCart);
                Shop.state.cart = serverCart;
                return;
            }

            const local = this.load();
            if (local && local.items?.length > 0) {
                // Session expired but localStorage has cart — restore to server
                await fetch('api.php?action=cart_restore', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': Shop.csrfToken,
                    },
                    body: JSON.stringify(local.items),
                });
                const restored = await fetch('api.php?action=cart').then(r => r.json());
                Shop.state.cart = restored;
                this.save(restored);
                return;
            }

            Shop.state.cart = serverCart;
        } catch (e) {
            console.error('Cart restore failed:', e);
        }
    },
};

// ============================================================
// Boot
// ============================================================
document.addEventListener('DOMContentLoaded', () => Shop.init());
