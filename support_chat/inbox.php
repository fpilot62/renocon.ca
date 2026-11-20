<?php
require_once __DIR__ . '/lib.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
	@session_start();
}
$loggedIn = support_chat_admin_ok();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Payroll support inbox</title>
  <style>
    body { font-family: 'Segoe UI', sans-serif; background: #f1f5f9; margin: 0; color: #0f172a; }
    .wrap { max-width: 1100px; margin: 24px auto; padding: 0 16px; }
    .card { background: #fff; border-radius: 12px; box-shadow: 0 8px 24px rgba(15,23,42,.08); padding: 20px; }
    h1 { margin: 0 0 8px; font-size: 22px; }
    .sub { color: #64748b; margin-bottom: 18px; }
    input, textarea, button { font: inherit; }
    input, textarea { width: 100%; box-sizing: border-box; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; }
    button { background: #0891b2; color: #fff; border: 0; border-radius: 8px; padding: 10px 16px; font-weight: 700; cursor: pointer; }
    button.secondary { background: #fff; color: #0f172a; border: 1px solid #cbd5e1; }
    .error { background: #fee2e2; color: #b91c1c; padding: 8px 10px; border-radius: 8px; margin-bottom: 10px; }
    .layout { display: grid; grid-template-columns: 320px 1fr; gap: 16px; }
    .thread { padding: 12px; border-bottom: 1px solid #e2e8f0; cursor: pointer; }
    .thread:hover, .thread.active { background: #ecfeff; }
    .thread b { display: block; }
    .thread span { color: #64748b; font-size: 12px; }
    .msg { margin: 0 0 10px; padding: 10px 12px; border-radius: 10px; max-width: 80%; }
    .msg.customer { background: #e2e8f0; }
    .msg.support { background: #0891b2; color: #fff; margin-left: auto; }
    #messages { min-height: 280px; max-height: 52vh; overflow: auto; padding-right: 6px; }
    .meta { font-size: 13px; color: #475569; margin-bottom: 12px; }
    @media (max-width: 800px) { .layout { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h1>Payroll support inbox</h1>
      <p class="sub">Reply to customers who used Need help? on payroll.renocon.ca</p>
      <?php if (!$loggedIn) { ?>
        <form id="login-form">
          <p>Admin password from <code>support_chat/config.php</code></p>
          <input type="password" name="password" required placeholder="Admin password">
          <p><button type="submit">Open inbox</button></p>
          <div id="login-error" class="error" style="display:none"></div>
        </form>
      <?php } else { ?>
        <p><button type="button" class="secondary" id="logout">Log out</button></p>
        <div class="layout">
          <div id="list"></div>
          <div>
            <div class="meta" id="meta">Select a conversation</div>
            <div id="messages"></div>
            <form id="reply-form" style="display:none">
              <textarea name="message" rows="4" required placeholder="Type your reply"></textarea>
              <p><button type="submit">Send reply</button> <button type="button" class="secondary" id="close-thread">Close</button></p>
            </form>
          </div>
        </div>
      <?php } ?>
    </div>
  </div>
  <script>
    var currentId = null;
    function api(action, payload, method) {
      var url = "api.php?action=" + encodeURIComponent(action);
      var opts = { method: method || "POST", headers: { "Content-Type": "application/json" } };
      if (opts.method === "GET") {
        Object.keys(payload || {}).forEach(function (key) {
          url += "&" + encodeURIComponent(key) + "=" + encodeURIComponent(payload[key]);
        });
      } else {
        opts.body = JSON.stringify(payload || {});
      }
      return fetch(url, opts).then(function (r) { return r.json(); });
    }
    function esc(s) { return String(s || "").replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;"); }
    var login = document.getElementById("login-form");
    if (login) {
      login.addEventListener("submit", function (e) {
        e.preventDefault();
        api("admin_login", { password: login.password.value }).then(function (res) {
          if (!res.ok) {
            var box = document.getElementById("login-error");
            box.style.display = "block";
            box.textContent = res.error;
            return;
          }
          location.reload();
        });
      });
    }
    var logout = document.getElementById("logout");
    if (logout) {
      logout.addEventListener("click", function () { api("admin_logout", {}).then(function () { location.reload(); }); });
      loadList();
    }
    function loadList() {
      api("admin_list", {}).then(function (res) {
        if (!res.ok) return;
        var list = document.getElementById("list");
        list.innerHTML = "";
        (res.threads || []).forEach(function (t) {
          var row = document.createElement("div");
          row.className = "thread" + (t.id === currentId ? " active" : "");
          row.innerHTML = "<b>" + esc(t.customer_name) + "</b><span>" + esc(t.preview) + "</span>";
          row.addEventListener("click", function () { openThread(t.id); });
          list.appendChild(row);
        });
      });
    }
    function openThread(id) {
      currentId = id;
      api("admin_thread", { thread_id: id }, "GET").then(function (res) {
        if (!res.ok) return;
        var t = res.thread;
        document.getElementById("meta").innerHTML = "<b>" + esc(t.customer_name) + "</b> &lt;" + esc(t.customer_email) + "&gt;<br>" + esc(t.page_url);
        var box = document.getElementById("messages");
        box.innerHTML = "";
        t.messages.forEach(function (m) {
          var d = document.createElement("div");
          d.className = "msg " + m.role;
          d.innerHTML = esc(m.text);
          box.appendChild(d);
        });
        box.scrollTop = box.scrollHeight;
        document.getElementById("reply-form").style.display = "block";
        loadList();
      });
    }
    var reply = document.getElementById("reply-form");
    if (reply) {
      reply.addEventListener("submit", function (e) {
        e.preventDefault();
        if (!currentId) return;
        api("admin_reply", { thread_id: currentId, message: reply.message.value }).then(function (res) {
          if (res.ok) {
            reply.message.value = "";
            openThread(currentId);
          }
        });
      });
      document.getElementById("close-thread").addEventListener("click", function () {
        if (!currentId) return;
        api("admin_close", { thread_id: currentId }).then(function () { openThread(currentId); });
      });
    }
  </script>
</body>
</html>
