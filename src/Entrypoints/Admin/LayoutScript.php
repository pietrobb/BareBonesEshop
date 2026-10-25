<?php
declare(strict_types=1);

/**
 * Script at the end of every admin page: CSP-safe delegated handlers (replace inline on*= attributes,
 * so script-src stays nonce-only) and the phone layout of wide tables (R10 UX10).
 */
function adminLayoutScript(): string {
    return '<script nonce="' . Security::cspNonce() . '">' . <<<'JS'
// CSP-safe delegated handlers — replaces inline on*= attributes so the admin
// CSP can drop 'unsafe-inline' from script-src (nonce-only). See data-confirm
// and data-action hooks in the templates above.
(function(){
    document.addEventListener('submit', function(e){
        var f = e.target;
        var submitter = e.submitter;
        var skipConfirm = submitter && submitter.hasAttribute && submitter.hasAttribute('data-skip-confirm');
        var message = submitter && submitter.getAttribute ? submitter.getAttribute('data-confirm') : null;
        if (!message && !skipConfirm && f && f.matches && f.matches('form[data-confirm]')) message = f.getAttribute('data-confirm');
        if (message && !confirm(message)) e.preventDefault();
    });
    document.addEventListener('click', function(e){
        var t = e.target.closest ? e.target.closest('[data-action]') : null;
        if (!t) return;
        var a = t.getAttribute('data-action');
        if (a === 'remove-parent') { e.preventDefault(); if (t.parentElement) t.parentElement.remove(); }
        else if (a === 'add-option') { e.preventDefault(); if (window.addOption) window.addOption(); }
        else if (a === 'toggle-cats') { e.preventDefault(); if (window.toggleAllCats) window.toggleAllCats(t.getAttribute('data-value') === '1'); }
    });
    document.addEventListener('input', function(e){
        var t = e.target;
        if (t && t.matches && t.matches('[data-action="filter-cats"]') && window.filterCats) window.filterCats();
    });
    // Wide tables scroll inside their own box on a phone instead of the page; a box that
    // actually scrolls is keyboard-reachable and named after the heading above it.
    var main = document.querySelector('main');
    if (!main) return;
    main.querySelectorAll('table').forEach(function(table){
        if (table.parentElement.closest('.table-scroll')) return;
        var box = document.createElement('div');
        box.className = 'table-scroll';
        table.parentNode.insertBefore(box, table);
        box.appendChild(table);
    });
    function headingBefore(el){
        var all = Array.prototype.slice.call(main.querySelectorAll('h1,h2,h3,h4,summary'));
        for (var i = all.length - 1; i >= 0; i--) {
            if (all[i].compareDocumentPosition(el) & Node.DOCUMENT_POSITION_FOLLOWING) return all[i].textContent.trim();
        }
        return document.title;
    }
    function markScrollers(){
        main.querySelectorAll('.table-scroll').forEach(function(box){
            if (box.scrollWidth > box.clientWidth + 1) {
                box.setAttribute('tabindex', '0');
                box.setAttribute('role', 'region');
                box.setAttribute('aria-label', headingBefore(box));
            } else if (box.getAttribute('tabindex') === '0') {
                box.removeAttribute('tabindex');
                box.removeAttribute('role');
                box.removeAttribute('aria-label');
            }
        });
    }
    markScrollers();
    window.addEventListener('resize', markScrollers);
})();
JS
    . '</script>';
}
