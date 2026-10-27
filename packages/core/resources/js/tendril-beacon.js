/*! Tendril beacon: first-party, dependency-free, no cookies of its own. */
(function (w, d) {
    'use strict';
    var s = d.currentScript;
    if (!s || !w.navigator || w.__tendril) return;
    w.__tendril = true;

    var endpoint = s.getAttribute('data-endpoint') || '/tendril/collect';
    var trackOutbound = s.getAttribute('data-outbound') !== 'false';
    var trackEngagement = s.getAttribute('data-engagement') !== 'false';
    var trackNavigate = s.getAttribute('data-navigate') === 'true';
    var B32 = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    var started = Date.now();

    function ulid() {
        var t = Date.now(), out = '', i, r = new Uint8Array(16);
        for (i = 9; i >= 0; i--) { out = B32.charAt(t % 32) + out; t = Math.floor(t / 32); }
        (w.crypto || w.msCrypto).getRandomValues(r);
        for (i = 0; i < 16; i++) out += B32.charAt(r[i] % 32);
        return out;
    }

    function utm() {
        var p = new URLSearchParams(w.location.search), o = {}, k = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];
        k.forEach(function (n) { if (p.get(n)) o[n] = p.get(n); });
        return o;
    }

    function send(name, props) {
        var e = { id: ulid(), name: name, ts: Date.now(), path: w.location.pathname, referrer: d.referrer || null, utm: utm(), properties: props || {} };
        var body = JSON.stringify({ events: [e] });
        var blob = new Blob([body], { type: 'text/plain' });
        if (!(w.navigator.sendBeacon && w.navigator.sendBeacon(endpoint, blob))) {
            try { w.fetch(endpoint, { method: 'POST', body: body, keepalive: true, credentials: 'same-origin', headers: { 'Content-Type': 'text/plain' } }); } catch (x) {}
        }
    }

    send('page.viewed');

    if (trackNavigate) {
        d.addEventListener('livewire:navigated', function () { started = Date.now(); send('page.viewed'); });
    }

    if (trackOutbound) {
        d.addEventListener('click', function (ev) {
            var a = ev.target && ev.target.closest ? ev.target.closest('a[href]') : null;
            if (!a || !a.host || a.host === w.location.host) return;
            send('outbound.clicked', { host: a.host });
        }, true);
    }

    if (trackEngagement) {
        d.addEventListener('visibilitychange', function () {
            if (d.visibilityState !== 'hidden') return;
            var seconds = Math.round((Date.now() - started) / 1000);
            if (seconds > 0) send('engagement.recorded', { seconds: seconds });
            started = Date.now();
        });
    }
})(window, document);
