// js/websocket-client.js
// Robust reconnecting WebSocket client for notifications page
// Include this file from notifications.php (already included in the page).
(function () {
  'use strict';

  const WS_URL = (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') ?
    'ws://127.0.0.1:8080' : 'ws://' + window.location.hostname + ':8080';

  let ws = null;
  let reconnectTimeout = 1000;
  let maxReconnect = 30000;

  function connect() {
    try {
      ws = new WebSocket(WS_URL);
    } catch (e) {
      console.warn('WebSocket constructor failed', e);
      scheduleReconnect();
      return;
    }

    ws.addEventListener('open', function () {
      console.log('WebSocket connected to', WS_URL);
      reconnectTimeout = 1000; // reset backoff
    });

    ws.addEventListener('message', function (evt) {
      if (!evt || !evt.data) return;
      let data;
      try {
        data = JSON.parse(evt.data);
      } catch (e) {
        console.warn('Invalid JSON from websocket:', evt.data);
        return;
      }
      handleMessage(data);
    });

    ws.addEventListener('close', function () {
      console.warn('WebSocket closed, scheduling reconnect in', reconnectTimeout);
      scheduleReconnect();
    });

    ws.addEventListener('error', function (err) {
      console.warn('WebSocket error', err);
      try { ws.close(); } catch(e){}
      scheduleReconnect();
    });
  }

  function scheduleReconnect() {
    setTimeout(function () {
      reconnectTimeout = Math.min(maxReconnect, reconnectTimeout * 1.5);
      connect();
    }, reconnectTimeout);
  }

  function showToast(message, type) {
    if (typeof window.showToast === 'function') {
      try { window.showToast(message, type); return; } catch(e) {}
    }
    // fallback simple alert for debug
    console.info('TOAST [' + (type||'info') + ']: ' + message);
  }

  function prependNotificationRow(data) {
    const tbody = document.getElementById('notificationsTableBody');
    if (!tbody) return;

    const tr = document.createElement('tr');
    tr.setAttribute('data-id', data.notification_id || '');
    if (data.bin_id) tr.setAttribute('data-bin-id', data.bin_id);
    if (data.janitor_id) tr.setAttribute('data-janitor-id', data.janitor_id);
    tr.setAttribute('data-title', data.title || '');
    tr.setAttribute('data-message', data.message || '');

    const timeTd = document.createElement('td');
    timeTd.textContent = data.created_at ? data.created_at : (new Date()).toISOString().slice(0,16).replace('T',' ');

    const typeTd = document.createElement('td');
    typeTd.textContent = (data.notification_type || data.type || 'info').toString().charAt(0).toUpperCase() + (data.notification_type || data.type || 'info').toString().slice(1);

    const titleTd = document.createElement('td');
    titleTd.textContent = data.title || (data.message ? data.message.split('\n',1)[0] : 'Notification');

    const msgTd = document.createElement('td');
    msgTd.className = 'd-none d-md-table-cell';
    msgTd.innerHTML = '<small class="text-muted">' + (data.message || '') + '</small>';

    const tgtTd = document.createElement('td');
    tgtTd.className = 'd-none d-lg-table-cell';
    if (data.bin_code) tgtTd.textContent = data.bin_code;
    else if (data.bin_id) tgtTd.textContent = 'Bin #' + data.bin_id;
    else if (data.janitor_name) tgtTd.textContent = data.janitor_name;
    else if (data.janitor_id) tgtTd.textContent = 'Janitor #' + data.janitor_id;
    else tgtTd.textContent = '-';

    const actionTd = document.createElement('td');
    actionTd.className = 'text-end';
    const btn = document.createElement('button');
    btn.className = 'btn btn-sm btn-success mark-read-btn';
    if (data.notification_id) btn.setAttribute('data-id', data.notification_id);
    btn.innerHTML = '<i class="fas fa-check me-1"></i>Read';
    actionTd.appendChild(btn);

    tr.appendChild(timeTd);
    tr.appendChild(typeTd);
    tr.appendChild(titleTd);
    tr.appendChild(msgTd);
    tr.appendChild(tgtTd);
    tr.appendChild(actionTd);

    // Prepend
    if (tbody.firstChild) tbody.insertBefore(tr, tbody.firstChild);
    else tbody.appendChild(tr);
  }

  function handleMessage(data) {
    // Accept both direct notification payloads and generic bin events
    if (!data || !data.type) return;

    if (data.type === 'notification') {
      prependNotificationRow(data);
      showToast((data.title || 'Notification') + (data.message ? ': ' + data.message : ''), data.notification_type || 'info');
    } else if (data.type.startsWith('bin_') || data.type === 'bin_status_changed' || data.type === 'bin_toggled' || data.type === 'bin_reassigned' || data.type === 'bin_calibrate') {
      // create a small notification to admin
      const title = data.title || (data.bin_id ? 'Bin #' + data.bin_id + ' updated' : 'Bin updated');
      const message = data.message || '';
      const payload = {
        type: 'notification',
        notification_id: data.notification_id || '',
        notification_type: 'info',
        title: title,
        message: message,
        bin_id: data.bin_id || '',
        created_at: data.created_at || new Date().toISOString().slice(0,16).replace('T',' ')
      };
      prependNotificationRow(payload);
      showToast(title + (message ? ': ' + message : ''), 'info');
    } else {
      // unknown message - try to surface as info
      prependNotificationRow({
        type: 'notification',
        title: data.title || data.type || 'Message',
        message: data.message || JSON.stringify(data),
        created_at: data.created_at || new Date().toISOString().slice(0,16).replace('T',' ')
      });
      showToast(data.title || data.type || 'Message', 'info');
    }
  }

  // Start
  connect();

  // expose for debugging
  window._notificationWs = {
    connect: connect
  };
})();