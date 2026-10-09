/* Offline login / logout.

   Every time someone logs in successfully while online, this device keeps a
   salted, slow hash (PBKDF2-SHA256) of their password — never the password
   itself. Offline, the login form checks the typed password against that
   hash and opens the saved pages; Logout offline locks the app again.

   Server session vs. device session:
   - Online login/logout work exactly as before (the server decides); this
     only records the outcome.
   - Logout made offline is finished on the server the next time the app is
     online (pendingLogout).
   - If someone different logs in offline than the account the server still
     has a session for, that session is closed when back online and they're
     asked to log in again, so their offline sales never sync under someone
     else's name.

   State (localStorage 'posOfflineAuth'):
     { users: { email: {salt, hash, iter, username, role, home} },
       session: {email, username, home} | null,
       loggedOut: bool, pendingLogout: bool } */
(function () {
  var KEY = 'posOfflineAuth';
  var PENDING = 'posPendingCred';
  var ITER = 120000;
  var base = (window.POS_AUTH_BASE || (window.POS_OFFLINE && window.POS_OFFLINE.scope) || '/').replace(/\/?$/, '/');
  var LOGIN = base + 'auth/login.php';
  var LOGOUT = base + 'auth/logout.php';

  function load() {
    try {
      var s = JSON.parse(localStorage.getItem(KEY) || 'null');
      if (s && typeof s === 'object') { s.users = s.users || {}; return s; }
    } catch (ignored) {}
    return { users: {}, session: null, loggedOut: false, pendingLogout: false };
  }
  function save(s) { try { localStorage.setItem(KEY, JSON.stringify(s)); } catch (ignored) {} }
  function norm(email) { return String(email || '').trim().toLowerCase(); }

  function hex(buf) {
    return Array.prototype.map.call(new Uint8Array(buf), function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }
  function unhex(h) {
    var out = new Uint8Array(h.length / 2);
    for (var i = 0; i < out.length; i++) out[i] = parseInt(h.substr(i * 2, 2), 16);
    return out;
  }
  function derive(password, saltHex, iter) {
    var enc = new TextEncoder();
    return crypto.subtle.importKey('raw', enc.encode(password), 'PBKDF2', false, ['deriveBits'])
      .then(function (key) {
        return crypto.subtle.deriveBits({ name: 'PBKDF2', hash: 'SHA-256', salt: unhex(saltHex), iterations: iter }, key, 256);
      })
      .then(hex);
  }
  function canHash() { return !!(window.crypto && crypto.subtle && window.TextEncoder); }

  function isOffline() {
    if (window.AppOnline && typeof AppOnline.isOnline === 'function') return !AppOnline.isOnline();
    return navigator.onLine === false;
  }

  /** Login page, online: hash what was typed; it's kept only if the login succeeds. */
  function rememberAttempt(email, password) {
    if (!canHash() || !email || !password) return Promise.resolve();
    var salt = hex(crypto.getRandomValues(new Uint8Array(16)));
    return derive(password, salt, ITER).then(function (hash) {
      try { sessionStorage.setItem(PENDING, JSON.stringify({ email: norm(email), salt: salt, hash: hash, iter: ITER })); } catch (ignored) {}
    }, function () {});
  }

  /** Any logged-in page loaded from the server: the login worked — keep the hash. */
  function commitLogin(user) {
    if (!user || !user.email) return;
    var s = load();
    var pending = null;
    try { pending = JSON.parse(sessionStorage.getItem(PENDING) || 'null'); sessionStorage.removeItem(PENDING); } catch (ignored) {}
    var email = norm(user.email);
    var fromLogin = /\/auth\/login\.php/.test(document.referrer || '');
    if (pending && fromLogin && pending.email === email) {
      s.users[email] = { salt: pending.salt, hash: pending.hash, iter: pending.iter, username: user.username, role: user.role, home: user.home };
    } else if (s.users[email]) {
      s.users[email].username = user.username; s.users[email].role = user.role; s.users[email].home = user.home;
    }
    s.session = { email: email, username: user.username, home: user.home };
    s.loggedOut = false;
    s.pendingLogout = false;
    save(s);
  }

  /** Offline login. Resolves {ok, home} or {ok:false, error}. */
  function offlineLogin(email, password) {
    var s = load();
    var u = s.users[norm(email)];
    if (!u) return Promise.resolve({ ok: false, error: 'This account hasn\'t logged in on this device while online yet. Connect to the internet and log in once — after that it works offline.' });
    if (!canHash()) return Promise.resolve({ ok: false, error: 'This browser can\'t check passwords offline.' });
    return derive(password, u.salt, u.iter || ITER).then(function (hash) {
      if (hash !== u.hash) return { ok: false, error: 'Invalid email or password.' };
      var home = u.home;
      var check = (home && window.caches) ? caches.match(home, { ignoreSearch: true }) : Promise.resolve(true);
      return check.then(function (hit) {
        if (!hit) return { ok: false, error: 'This account\'s pages aren\'t saved on this device yet. Log in once while online.' };
        s.session = { email: norm(email), username: u.username, home: home };
        s.loggedOut = false;
        save(s);
        return { ok: true, home: home };
      });
    });
  }

  /** Logout from anywhere. Online: the server logs out. Offline: lock the app now, tell the server later. */
  function logout() {
    var s = load();
    s.session = null;
    s.loggedOut = true;
    if (isOffline()) {
      s.pendingLogout = true;
      save(s);
      location.href = LOGIN;
    } else {
      s.pendingLogout = false;
      save(s);
      location.href = LOGOUT;
    }
  }

  /**
   * Runs at the top of every logged-in page (pwa_head). Returns true if it's
   * sending the browser elsewhere (page should do nothing else, e.g. no sync).
   * user = who the server says is logged in on this page (as rendered).
   */
  function guardPage(user) {
    var s = load();
    var offline = isOffline();
    if (s.loggedOut) {
      if (offline) {
        // Logged out on this device: saved pages stay locked until login.
        window.POS_AUTH_BLOCK = true;
        location.replace(LOGIN);
        return true;
      }
      if (s.pendingLogout) {
        // Logged out offline earlier; the server session still exists.
        window.POS_AUTH_BLOCK = true;
        location.replace(LOGOUT);
        return true;
      }
      // Online and the server says someone is logged in: a fresh login.
    }
    if (!user || !user.email) return false;
    var sessionEmail = !s.loggedOut && s.session && s.session.email;
    if (sessionEmail && sessionEmail !== norm(user.email)) {
      // Someone else logged in offline. Offline, the saved pages are fine to
      // use; online, the server's session is the wrong person — don't sync
      // their sales under it: log the server out and ask for a real login.
      if (offline) return false;
      window.POS_AUTH_BLOCK = true;
      s.session = null; s.loggedOut = true; s.pendingLogout = false; save(s);
      try { sessionStorage.setItem('posAuthNotice', 'Please log in again to sync the sales made offline as ' + sessionEmail + '.'); } catch (ignored) {}
      location.replace(LOGOUT);
      return true;
    }
    if (!offline) commitLogin(user);
    return false;
  }

  /** Login page wiring. */
  function wireLoginForm(form) {
    if (!form) return;
    var s = load();
    if (/[?&]logout=1/.test(location.search)) {
      s.session = null; s.loggedOut = true;
      if (isOffline()) s.pendingLogout = true;
      save(s);
    }
    // Offline with someone still logged in on this device: straight to the till.
    if (isOffline() && !s.loggedOut && s.session && s.session.home) {
      location.replace(s.session.home);
      return;
    }
    // A logout made offline is finished on the server now that we're online.
    var ready = Promise.resolve();
    if (!isOffline() && s.pendingLogout) {
      var ac = ('AbortController' in window) ? new AbortController() : null;
      if (ac) setTimeout(function () { ac.abort(); }, 6000); // never hold up the login
      ready = fetch(LOGOUT, { credentials: 'same-origin', redirect: 'manual', cache: 'no-store', signal: ac ? ac.signal : undefined }).then(function () {
        var t = load(); t.pendingLogout = false; save(t);
      }, function () {});
    }
    var notice = null;
    try { notice = sessionStorage.getItem('posAuthNotice'); sessionStorage.removeItem('posAuthNotice'); } catch (ignored) {}
    if (notice) showMsg(form, notice, 'ok');

    var busy = false;
    form.addEventListener('submit', function (e) {
      if (busy) return;
      var email = (form.querySelector('[name="email"]') || {}).value || '';
      var password = (form.querySelector('[name="password"]') || {}).value || '';
      e.preventDefault();
      busy = true;
      if (isOffline()) {
        offlineLogin(email, password).then(function (r) {
          busy = false;
          if (r.ok) location.href = r.home;
          else showMsg(form, r.error, 'err');
        }, function () { busy = false; showMsg(form, 'Could not log in offline.', 'err'); });
        return;
      }
      // Online: normal server login; remember the hash in case it succeeds.
      Promise.all([ready, rememberAttempt(email, password)]).then(function () { form.submit(); });
    });
    if (isOffline()) showMsg(form, 'You\'re offline — log in with the same email and password you use online.', 'ok');
  }

  function showMsg(form, text, kind) {
    var box = document.getElementById('posAuthMsg');
    if (!box) {
      box = document.createElement('div');
      box.id = 'posAuthMsg';
      form.parentNode.insertBefore(box, form);
    }
    box.className = 'auth-alert ' + kind;
    box.textContent = text;
  }

  // Every logout link (sidebar, mobile "More" sheet…) goes through logout().
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[href]');
    if (!a || a.classList.contains('t-top-logout')) return;
    if (a.href.split('?')[0].split('#')[0] !== new URL(LOGOUT, location.href).href) return;
    e.preventDefault();
    logout();
  }, true);

  window.PosAuth = { guardPage: guardPage, wireLoginForm: wireLoginForm, logout: logout, offlineLogin: offlineLogin, commitLogin: commitLogin, _state: load };
})();
