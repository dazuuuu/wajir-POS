<?php
// public/components/mobile_nav.php — app-style bottom tab bar on phones and
// tablets (≤ 992px). Include just before </body> in a layout that renders the
// sidebar. Built at runtime FROM the sidebar menu itself, so it always shows
// exactly what this user may see (permissions, switched-off features) with
// no second menu to keep in sync. Tapping a group (e.g. POS) slides up a sheet
// with its sub-menus; extra groups and Logout live under "More".
?>
<style>
@media (max-width: 992px) {
  .t-sidebar, .t-sidebar-overlay, .t-sidebar-toggle { display: none !important; }
  .t-main { margin-left: 0 !important; width: 100% !important; padding: 12px 12px calc(84px + env(safe-area-inset-bottom)) !important; }
  .t-topbar { margin: 0 0 12px !important; position: sticky; top: 0; z-index: 900; border-radius: 0 0 14px 14px !important; padding: calc(10px + env(safe-area-inset-top)) 16px 10px !important; }
  .t-topbar h1 { font-size: 1.05rem !important; }
  body { -webkit-tap-highlight-color: transparent; overscroll-behavior-y: contain; }
}
.m-tabbar { display: none; }
@media (max-width: 992px) {
  .m-tabbar {
    display: flex; position: fixed; left: 0; right: 0; bottom: 0; z-index: 1500;
    background: rgba(255,255,255,.97); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
    border-top: 1px solid #eef0f4; box-shadow: 0 -4px 18px rgba(16,24,40,.08);
    padding: 6px 4px calc(6px + env(safe-area-inset-bottom));
  }
  .m-tab {
    flex: 1 1 0; min-width: 0; border: 0; background: none; text-decoration: none;
    display: flex; flex-direction: column; align-items: center; gap: 3px; padding: 6px 2px;
    color: #6b7280; font: 600 .68rem/1.1 -apple-system, 'Segoe UI', Roboto, Arial, sans-serif; border-radius: 12px;
  }
  .m-tab i { font-size: 1.15rem; width: 1.5rem; text-align: center; }
  .m-tab span { max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .m-tab.active { color: var(--pos-violet, #4b006e); }
  .m-tab.active i { transform: translateY(-1px); }
  .m-tab:active { background: #f3f0f7; }
}
.m-sheet-overlay { position: fixed; inset: 0; z-index: 1600; background: rgba(15,23,42,.4); opacity: 0; pointer-events: none; transition: opacity .2s; }
.m-sheet-overlay.show { opacity: 1; pointer-events: auto; }
.m-sheet {
  position: fixed; left: 0; right: 0; bottom: 0; z-index: 1700; background: #fff;
  border-radius: 20px 20px 0 0; max-height: 75vh; overflow-y: auto; transform: translateY(105%);
  transition: transform .25s cubic-bezier(.2,.8,.2,1); padding: 8px 0 calc(16px + env(safe-area-inset-bottom));
  box-shadow: 0 -10px 30px rgba(16,24,40,.18);
}
.m-sheet.show { transform: translateY(0); }
.m-sheet-grip { width: 40px; height: 5px; border-radius: 3px; background: #d1d5db; margin: 4px auto 10px; }
.m-sheet h3 { font: 800 1rem/1.2 -apple-system, 'Segoe UI', Roboto, Arial, sans-serif; margin: 0 20px 8px; color: #1f2330; }
.m-sheet h4 { font: 700 .72rem/1.2 -apple-system, 'Segoe UI', Roboto, Arial, sans-serif; text-transform: uppercase; letter-spacing: .05em; color: #9ca3af; margin: 14px 20px 4px; }
.m-sheet a {
  display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 14px 20px;
  color: #1f2330; text-decoration: none; font: 600 .95rem/1.2 -apple-system, 'Segoe UI', Roboto, Arial, sans-serif; border-bottom: 1px solid #f3f4f6;
}
.m-sheet a::after { content: '\203A'; color: #c0c4cc; font-size: 1.3rem; }
.m-sheet a.active { color: var(--pos-violet, #4b006e); background: #faf5ff; }
.m-sheet a.m-danger { color: #b91c1c; }
.m-sheet a:active { background: #f3f4f6; }
@media (min-width: 993px) { .m-sheet, .m-sheet-overlay { display: none !important; } }
</style>
<script>
(function () {
  var nav = document.querySelector('.t-sidebar .t-nav');
  if (!nav) return;

  function label(el) {
    var s = el.querySelector('span');
    return (s ? s.textContent : el.textContent).replace(/\s+/g, ' ').trim();
  }
  function icon(el) {
    var i = el.querySelector('i.fas, i.far, i.fa, i.fab');
    if (!i || i.classList.contains('t-group-caret')) return 'fas fa-circle';
    return Array.prototype.filter.call(i.classList, function (c) { return /^fa/.test(c); }).join(' ');
  }

  // Top-level entries straight from the sidebar.
  var entries = [];
  Array.prototype.forEach.call(nav.children, function (el) {
    if (el.matches('a.t-link')) {
      entries.push({ type: 'link', label: label(el), icon: icon(el), href: el.href, active: el.classList.contains('active') });
    } else if (el.matches('.t-group')) {
      var toggle = el.querySelector('.t-group-toggle');
      var subs = Array.prototype.map.call(el.querySelectorAll('.t-subnav a'), function (a) {
        return { label: a.textContent.replace(/\s+/g, ' ').trim(), href: a.href, active: a.classList.contains('active') };
      });
      if (!toggle || !subs.length) return;
      entries.push({ type: 'group', label: label(toggle), icon: icon(toggle), subs: subs,
        active: toggle.classList.contains('active') || subs.some(function (s) { return s.active; }) });
    }
  });
  var logout = document.querySelector('.t-sidebar .t-sidebar-footer a');
  if (!entries.length) return;

  // Four main tabs; anything else (plus Logout) goes under "More".
  var main = entries.length > 5 ? entries.slice(0, 4) : entries.slice(0, 5);
  var rest = entries.slice(main.length);
  if (rest.length || logout) {
    var moreActive = rest.some(function (e) { return e.active; });
    main.push({ type: 'more', label: 'More', icon: 'fas fa-ellipsis', active: moreActive && !main.some(function (e) { return e.active; }) });
  }

  // Sheet
  var overlay = document.createElement('div'); overlay.className = 'm-sheet-overlay';
  var sheet = document.createElement('div'); sheet.className = 'm-sheet'; sheet.setAttribute('role', 'dialog');
  document.body.appendChild(overlay); document.body.appendChild(sheet);
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function links(list) {
    return list.map(function (s) { return '<a href="' + esc(s.href) + '"' + (s.active ? ' class="active"' : '') + '>' + esc(s.label) + '</a>'; }).join('');
  }
  function open(html) { sheet.innerHTML = '<div class="m-sheet-grip"></div>' + html; overlay.classList.add('show'); sheet.classList.add('show'); }
  function close() { overlay.classList.remove('show'); sheet.classList.remove('show'); }
  overlay.addEventListener('click', close);
  // Swipe the sheet down to close it.
  var startY = null;
  sheet.addEventListener('touchstart', function (e) { if (sheet.scrollTop <= 0) startY = e.touches[0].clientY; }, { passive: true });
  sheet.addEventListener('touchmove', function (e) { if (startY !== null && e.touches[0].clientY - startY > 70) { startY = null; close(); } }, { passive: true });
  sheet.addEventListener('touchend', function () { startY = null; });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });

  // Tab bar
  var bar = document.createElement('nav');
  bar.className = 'm-tabbar';
  bar.setAttribute('aria-label', 'Main');
  main.forEach(function (e) {
    var el = document.createElement(e.type === 'link' ? 'a' : 'button');
    el.className = 'm-tab' + (e.active ? ' active' : '');
    if (e.type === 'link') el.href = e.href; else el.type = 'button';
    el.innerHTML = '<i class="' + esc(e.icon) + '"></i><span>' + esc(e.label) + '</span>';
    if (e.type === 'group') {
      el.addEventListener('click', function () { open('<h3>' + esc(e.label) + '</h3>' + links(e.subs)); });
    } else if (e.type === 'more') {
      el.addEventListener('click', function () {
        var html = '<h3>More</h3>';
        rest.forEach(function (r) {
          html += r.type === 'group' ? '<h4>' + esc(r.label) + '</h4>' + links(r.subs) : links([r]);
        });
        if (logout) html += '<h4>Account</h4><a class="m-danger" href="' + esc(logout.href) + '">Logout</a>';
        open(html);
      });
    }
    bar.appendChild(el);
  });
  document.body.appendChild(bar);
})();
</script>
