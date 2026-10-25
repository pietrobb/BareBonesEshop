<?php
/**
 * R10 UX10: a small rich-text editor for the admin's HTML fields (product description and its translations, CMS
 * page body). Progressive enhancement: every textarea[data-rich-text] (and the product/page form fields named
 * below) gets a toolbar and an editable area that writes HTML back into the textarea, so the form posts exactly as
 * before and works without JavaScript. Content is filtered to the tags and link schemes Security::sanitizeRichHtml()
 * keeps before it enters the editable area (stored HTML, paste, switching back from the HTML view); the server
 * sanitizer stays authoritative.
 */
function adminRichTextScript(): string {
    $labels = [];
    foreach (['bold', 'italic', 'h2', 'h3', 'ul', 'ol', 'link', 'link_prompt', 'clear', 'html', 'toolbar'] as $key) {
        $labels[$key] = I18n::t('admin.rich_text.' . $key);
    }
    $json = json_encode($labels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    return '<script nonce="' . Security::cspNonce() . '">(function(L){' . <<<'JS'
var ALLOWED = {P:1,BR:1,STRONG:1,EM:1,B:1,I:1,U:1,UL:1,OL:1,LI:1,H2:1,H3:1,H4:1,H5:1,H6:1,BLOCKQUOTE:1,CODE:1,PRE:1,A:1};
var DROP = {SCRIPT:1,STYLE:1,IFRAME:1,OBJECT:1,EMBED:1,FORM:1,INPUT:1,BUTTON:1,TEXTAREA:1,SELECT:1,SVG:1,MATH:1,TEMPLATE:1,LINK:1,META:1,IMG:1,VIDEO:1,AUDIO:1};
function safeHref(h){ h = (h || '').replace(/[\x00-\x20\x7F]+/g, ''); return h !== '' && h.indexOf('\\') < 0 && /^(?:https?:\/\/|mailto:|tel:|\/(?!\/)|[#?]|[^:\/?#]+(?:[\/?#]|$))/i.test(h) ? h : null; }
function clean(node){
  Array.prototype.slice.call(node.childNodes).forEach(function(c){
    if (c.nodeType === 8) { c.remove(); return; }
    if (c.nodeType !== 1) return;
    var tag = c.tagName.toUpperCase();
    if (DROP[tag]) { c.remove(); return; }
    clean(c);
    if (!ALLOWED[tag]) { while (c.firstChild) node.insertBefore(c.firstChild, c); c.remove(); return; }
    Array.prototype.slice.call(c.attributes).forEach(function(a){ if (tag !== 'A' || (a.name !== 'href' && a.name !== 'title')) c.removeAttribute(a.name); });
    if (tag === 'A' && c.hasAttribute('href')) { var h = safeHref(c.getAttribute('href')); if (h === null) c.removeAttribute('href'); }
  });
}
function cleanHtml(html){ var t = document.createElement('template'); t.innerHTML = html; clean(t.content); var d = document.createElement('div'); d.appendChild(t.content); return d.innerHTML; }
function button(text, title, run){
  var b = document.createElement('button'); b.type = 'button'; b.className = 'btn btn-sm'; b.textContent = text; b.title = title; b.setAttribute('aria-label', title);
  b.addEventListener('mousedown', function(e){ e.preventDefault(); }); b.addEventListener('click', run); return b;
}
function enhance(ta){
  if (ta.getAttribute('data-rich-ready')) return; ta.setAttribute('data-rich-ready', '1');
  var label = ta.labels && ta.labels[0] ? ta.labels[0].textContent.trim() : (ta.getAttribute('aria-label') || ta.name);
  var bar = document.createElement('div'); bar.className = 'rich-toolbar'; bar.setAttribute('role', 'toolbar'); bar.setAttribute('aria-label', L.toolbar + ': ' + label); bar.setAttribute('data-rich-for', ta.id);
  var ed = document.createElement('div'); ed.className = 'rich-editor'; ed.contentEditable = 'true';
  ed.setAttribute('role', 'textbox'); ed.setAttribute('aria-multiline', 'true'); ed.setAttribute('aria-label', label);
  bar.style.cssText = 'display:flex;flex-wrap:wrap;gap:.25rem;margin:.25rem 0';
  ed.style.cssText = 'border:1px solid #d1d5db;border-radius:4px;padding:.4rem .6rem;background:#fff;overflow:auto;max-height:40em;min-height:' + Math.max(4, ta.rows || 4) * 1.5 + 'em';
  ed.innerHTML = cleanHtml(ta.value);
  var sync = function(){ ta.value = ed.innerHTML === '<br>' ? '' : ed.innerHTML; };
  var cmd = function(c, v){ return function(){ ed.focus(); document.execCommand(c, false, v); sync(); }; };
  var html = button(L.html, L.html, function(){
    var showSource = ta.hidden; ta.hidden = !showSource; ed.hidden = showSource; html.setAttribute('aria-pressed', showSource ? 'true' : 'false');
    if (!showSource) ed.innerHTML = cleanHtml(ta.value);
    Array.prototype.forEach.call(bar.querySelectorAll('button'), function(b){ if (b !== html) b.disabled = showSource; });
  });
  html.setAttribute('aria-pressed', 'false');
  [button('B', L.bold, cmd('bold')), button('I', L.italic, cmd('italic')), button('H2', L.h2, cmd('formatBlock', '<h2>')), button('H3', L.h3, cmd('formatBlock', '<h3>')),
   button('•', L.ul, cmd('insertUnorderedList')), button('1.', L.ol, cmd('insertOrderedList')),
   button(L.link, L.link, function(){ var h = safeHref(window.prompt(L.link_prompt, 'https://')); if (h) cmd('createLink', h)(); }),
   button(L.clear, L.clear, function(){ cmd('removeFormat')(); cmd('formatBlock', '<p>')(); }), html].forEach(function(b){ bar.appendChild(b); });
  ed.addEventListener('input', sync);
  ed.addEventListener('paste', function(e){
    var d = e.clipboardData; if (!d) return; e.preventDefault();
    var h = d.getData('text/html');
    document.execCommand('insertHTML', false, h ? cleanHtml(h) : d.getData('text/plain').replace(/[&<>"]/g, function(ch){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[ch]; }).replace(/\n/g, '<br>'));
    sync();
  });
  ta.hidden = true;
  ta.parentNode.insertBefore(bar, ta);
  ta.parentNode.insertBefore(ed, ta);
}
Array.prototype.forEach.call(document.querySelectorAll('textarea[data-rich-text], #form-admin-product textarea[name="description"], #form-admin-page textarea[name="body"]'), enhance);
JS . '})(' . $json . ');</script>';
}
