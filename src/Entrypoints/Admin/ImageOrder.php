<?php
/**
 * R14 UX7: image list of the product editor. Progressive enhancement of the "URL | alt" textarea: each image gets a
 * row (thumbnail, file name, main-image mark) with move up / move down / remove buttons that rewrite the textarea's
 * lines, so the form posts exactly as before and works without JavaScript. The server still validates every line.
 */
function adminImageOrderScript(): string {
    $labels = [];
    foreach (['list', 'main', 'up', 'down', 'remove'] as $key) $labels[$key] = I18n::t('admin.image_order.' . $key);
    $json = json_encode($labels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    return '<script nonce="' . Security::cspNonce() . '">(function(L){' . <<<'JS'
var ta = document.querySelector('textarea[name="images"]');
if (!ta) return;
var list = document.createElement('ol');
list.setAttribute('data-image-order', '');
list.setAttribute('aria-label', L.list);
list.style.cssText = 'list-style:none;padding:0;margin:.5rem 0';
ta.parentNode.insertBefore(list, ta.nextSibling);
function lines(){ return ta.value.split(/\r?\n/).filter(function(l){ return l.trim() !== ''; }); }
function write(l, focus){ ta.value = l.join('\n'); render(focus); }
function button(text, label, enabled, run){
  var b = document.createElement('button');
  b.type = 'button'; b.className = 'btn btn-sm'; b.textContent = text; b.disabled = !enabled;
  b.setAttribute('aria-label', label); b.addEventListener('click', run); return b;
}
function render(focus){
  list.textContent = '';
  var l = lines();
  l.forEach(function(line, i){
    var url = line.split('|')[0].trim(), name = url.split('/').pop() || url;
    var row = document.createElement('li');
    row.style.cssText = 'display:flex;align-items:center;gap:.5rem;margin:.25rem 0';
    if (/^(?:https:\/\/|\/(?!\/))/i.test(url)) {
      var img = document.createElement('img');
      img.src = url; img.alt = ''; img.width = 48; img.height = 48; img.loading = 'lazy'; img.style.objectFit = 'cover';
      row.appendChild(img);
    }
    var text = document.createElement('span');
    text.textContent = (i === 0 ? L.main + ': ' : '') + name;
    row.appendChild(text);
    row.appendChild(button('↑', L.up + ': ' + name, i > 0, function(){ var t = l[i - 1]; l[i - 1] = l[i]; l[i] = t; write(l, [i - 1, 0]); }));
    row.appendChild(button('↓', L.down + ': ' + name, i < l.length - 1, function(){ var t = l[i + 1]; l[i + 1] = l[i]; l[i] = t; write(l, [i + 1, 1]); }));
    row.appendChild(button('×', L.remove + ': ' + name, true, function(){ l.splice(i, 1); write(l, null); }));
    list.appendChild(row);
  });
  if (focus) { var target = list.children[focus[0]]; if (target) { var b = target.querySelectorAll('button')[focus[1]]; (b && !b.disabled ? b : target.querySelector('button')).focus(); } }
}
ta.addEventListener('input', function(){ render(null); });
render(null);
JS . '})(' . $json . ');</script>';
}
