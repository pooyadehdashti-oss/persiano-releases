(function () {
  'use strict';
  if (!window.PersianoMarketing || !PersianoMarketing.ajaxUrl) return;

  var prefix = PersianoMarketing.cookiePrefix || 'phm_';
  function getCookie(name) {
    var match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()\[\]\\\/\+^])/g, '\\$1') + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : '';
  }
  function setCookie(name, value, days) {
    var maxAge = days ? '; max-age=' + (days * 86400) : '';
    document.cookie = name + '=' + encodeURIComponent(value || '') + '; path=/; samesite=lax' + maxAge;
  }
  function uid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    return 's-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2);
  }
  function classifyReferrer(referrer) {
    if (!referrer) return 'direct';
    try {
      var host = new URL(referrer).hostname.replace(/^www\./, '').toLowerCase();
      if (host.indexOf('google.') === 0 || host.indexOf('google.') > -1) return 'google';
      if (host.indexOf('instagram.') > -1) return 'instagram';
      if (host.indexOf('facebook.') > -1 || host.indexOf('fb.') === 0) return 'facebook';
      if (host.indexOf('t.me') > -1 || host.indexOf('telegram.') > -1) return 'telegram';
      if (host === location.hostname.replace(/^www\./, '').toLowerCase()) return getCookie(prefix + 'source') || 'direct';
      return host || 'referral';
    } catch (e) {
      return 'referral';
    }
  }
  function formDataFor(type, extra) {
    var params = new URLSearchParams(location.search);
    var urlSource = params.get('utm_source') || '';
    var source = urlSource;
    var medium = params.get('utm_medium') || '';
    var campaign = params.get('utm_campaign') || PersianoMarketing.campaignKey || '';
    var campaignId = params.get('ph_campaign') || PersianoMarketing.campaignId || '';

    if (urlSource) {
      setCookie(prefix + 'source', source, 30);
      setCookie(prefix + 'medium', medium, 30);
      setCookie(prefix + 'campaign', campaign, 30);
      setCookie(prefix + 'campaign_id', campaignId, 30);
    } else {
      source = getCookie(prefix + 'source') || classifyReferrer(document.referrer);
      medium = getCookie(prefix + 'medium') || (source === 'direct' ? '' : 'referral');
      campaign = campaign || getCookie(prefix + 'campaign') || '';
      campaignId = campaignId || getCookie(prefix + 'campaign_id') || '';

      // A direct visit to a Persiano promotion or campaign-owned product still
      // belongs to that campaign even when the URL has no UTM parameters.
      if (campaignId) {
        setCookie(prefix + 'campaign_id', campaignId, 30);
        setCookie(prefix + 'campaign', campaign, 30);
      }
      if (!getCookie(prefix + 'source') && source && source !== 'direct') {
        setCookie(prefix + 'source', source, 30);
        setCookie(prefix + 'medium', medium, 30);
      }
    }

    var session = getCookie(prefix + 'session');
    if (!session) {
      session = uid();
      setCookie(prefix + 'session', session, 1 / 48);
    }

    var data = new FormData();
    data.append('action', 'persiano_hub_track_event');
    data.append('event_type', type);
    data.append('session_id', session);
    data.append('campaign_id', campaignId);
    data.append('object_id', PersianoMarketing.objectId || '');
    data.append('object_type', PersianoMarketing.objectType || '');
    data.append('source', source || 'direct');
    data.append('medium', medium || '');
    data.append('campaign_key', campaign || '');
    data.append('url', location.href);
    data.append('path', location.pathname);
    data.append('referrer', document.referrer || '');
    Object.keys(extra || {}).forEach(function (key) { data.append(key, extra[key] || ''); });
    return data;
  }
  function send(type, extra) {
    var data = formDataFor(type, extra);
    if (navigator.sendBeacon) {
      navigator.sendBeacon(PersianoMarketing.ajaxUrl, data);
      return;
    }
    fetch(PersianoMarketing.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin', keepalive: true }).catch(function () {});
  }
  function actionFor(el) {
    var href = (el.getAttribute('href') || '').toLowerCase();
    var text = (el.textContent || el.value || '').trim().toLowerCase();
    if (el.matches('.add_to_cart_button, [name="add-to-cart"]') || href.indexOf('add-to-cart') > -1) return 'add_to_cart';
    if (href.indexOf('/checkout') > -1 || text.indexOf('checkout') > -1) return 'checkout';
    if (href.indexOf('/cart') > -1) return 'cart';
    if (text.indexOf('order') > -1 || text.indexOf('buy') > -1) return 'order_cta';
    if (href.indexOf('instagram.com') > -1) return 'instagram';
    if (href.indexOf('t.me') > -1 || href.indexOf('telegram') > -1) return 'telegram';
    if (href.indexOf('mailto:') === 0) return 'email';
    if (href.indexOf('tel:') === 0) return 'phone';
    return 'link';
  }

  send('pageview', {});
  document.addEventListener('click', function (event) {
    var el = event.target.closest('a,button,input[type="submit"]');
    if (!el) return;
    send('click', {
      action_name: actionFor(el),
      label: (el.textContent || el.value || '').trim().slice(0, 160),
      url: el.href || location.href
    });
  }, true);
})();
