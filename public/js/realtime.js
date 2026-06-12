// ZealPulse — Phase 7 realtime console.
// Drives the WS live feed, the incident room, and the SSE metrics stream.
// (No inline JS in templates — separation of concerns; this is the only client.)
(function () {
  'use strict';

  var wsBase = (location.protocol === 'https:' ? 'wss://' : 'ws://') + location.host;

  function li(parent, text) {
    var el = document.createElement('li');
    el.textContent = text;
    parent.insertBefore(el, parent.firstChild);
    while (parent.childNodes.length > 50) parent.removeChild(parent.lastChild);
  }

  // ── live feed (WS /live) ───────────────────────────────────────────────────
  var feed = document.getElementById('feed');
  var online = document.getElementById('online');
  var live = new WebSocket(wsBase + '/live');
  live.onmessage = function (ev) {
    var m = JSON.parse(ev.data);
    if (m.type === 'welcome' || m.online !== undefined) online.textContent = m.online;
    if (m.type === 'note' || m.type === 'replay' || m.type === 'event') li(feed, '[' + m.type + '] ' + (m.msg || ''));
  };
  document.getElementById('noteSend').onclick = function () {
    var inp = document.getElementById('noteInput');
    if (inp.value.trim()) { live.send(inp.value.trim()); inp.value = ''; }
  };

  // ── incident room (WS /incident) ───────────────────────────────────────────
  var room = null;
  var roomFeed = document.getElementById('roomFeed');
  var members = document.getElementById('members');
  document.getElementById('roomJoin').onclick = function () {
    if (room) room.close();
    var r = encodeURIComponent(document.getElementById('roomName').value || 'general');
    var n = encodeURIComponent(document.getElementById('userName').value || 'you');
    room = new WebSocket(wsBase + '/incident?room=' + r + '&name=' + n);
    room.onmessage = function (ev) {
      var m = JSON.parse(ev.data);
      if (m.type === 'joined') members.textContent = (m.members || []).join(', ');
      else if (m.type === 'presence') { members.textContent = m.event + ' (' + m.size + ')'; li(roomFeed, '* ' + m.name + ' ' + m.event); }
      else if (m.type === 'message') li(roomFeed, m.from + ': ' + m.msg);
      else if (m.type === 'error') li(roomFeed, '! ' + m.error);
    };
  };
  document.getElementById('roomSend').onclick = function () {
    var inp = document.getElementById('roomInput');
    if (room && room.readyState === 1 && inp.value.trim()) { room.send(inp.value.trim()); inp.value = ''; }
  };

  // ── SSE metrics (/stream/metrics) ──────────────────────────────────────────
  var metrics = document.getElementById('metrics');
  var es = new EventSource('/stream/metrics');
  es.addEventListener('metrics', function (ev) { metrics.textContent = ev.data; });
  es.addEventListener('heartbeat', function () { /* keepalive */ });
  es.onerror = function () { metrics.textContent = '(metrics stream closed)'; };
})();
