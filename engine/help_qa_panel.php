<?php
/**
 * Collapsible Questions & answers for the current script.
 * Superadmins can add, edit, delete, and reorder. Other people see an
 * entry only when visible_roles, org_id, status, and requires_feature allow it.
 */
require_once __DIR__ . '/help_qa_lib.php';

function help_qa_panel(PDO $pdo): string
{
    if (session_status() === PHP_SESSION_NONE) {
        return '';
    }
    if (empty($_SESSION['user_id'])) {
        return '';
    }
    $pageKey = help_qa_page_key();
    if ($pageKey === '' || !help_qa_valid_page_key($pageKey)) {
        return '';
    }
    $level = (int)($_SESSION['user_level'] ?? 99);
    $editor = help_qa_is_superadmin();
    $orgId = help_qa_session_org();
    $qaReady = help_qa_table_ready($pdo);
    $askReady = help_user_question_table_ready($pdo);
    $rows = $qaReady ? help_qa_rows_for_page($pdo, $pageKey) : [];
    $visible = [];
    foreach ($rows as $row) {
        if ($editor || help_qa_reader_can_see($row, $level, $orgId)) {
            $visible[] = $row;
        }
    }
    $showList = $qaReady && ($editor || $visible);
    if (!$showList && !$askReady && !$editor) {
        return '';
    }
    if (!function_exists('ps_csrf_token')) {
        $scope = dirname(__DIR__) . '/payroll_scope.php';
        if (is_file($scope)) {
            require_once $scope;
        }
    }
    $csrf = function_exists('ps_csrf_token') ? ps_csrf_token() : '';
    $h = 'help_qa_h';
    $actionUrl = help_qa_action_url();
    $askNotice = trim((string)($_SESSION['help_ask_notice'] ?? ''));
    unset($_SESSION['help_ask_notice']);
    $uqStatus = help_user_question_status_filter((string)($_GET['uq_status'] ?? 'new'));
    $userQuestions = ($editor && $askReady) ? help_user_question_list($pdo, $uqStatus, $pageKey) : [];
    $html = '<div class="help-qa" id="helpQaPanel">';
    $html .= '<style>'
        . '.help-qa{max-width:1550px;margin:8px auto 0;padding:0 16px;font-family:Inter,Segoe UI,sans-serif;font-size:13px;color:#1e293b}'
        . '.help-qa-toggle{background:#fff;border:1px solid #cbd5e1;border-radius:10px;padding:8px 12px;font-weight:800;cursor:pointer}'
        . '.help-qa-body{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:12px 14px;margin-top:6px}'
        . '.help-qa-item{border-top:1px solid #e2e8f0;padding:10px 0}'
        . '.help-qa-item:first-child{border-top:0}'
        . '.help-qa-q{font-weight:800}'
        . '.help-qa-a{white-space:pre-wrap;margin:4px 0 0}'
        . '.help-qa-flag{display:inline-block;background:#fef3c7;color:#92400e;border-radius:99px;padding:1px 8px;font-size:11px;font-weight:800;margin-left:6px}'
        . '.help-qa-tools{display:flex;flex-wrap:wrap;gap:6px;margin-top:6px}'
        . '.help-qa-tools button,.help-qa-add button{font-size:12px}'
        . '.help-qa-edit{display:none;margin-top:8px}'
        . '.help-qa-edit.is-open{display:block}'
        . '.help-qa input,.help-qa textarea,.help-qa select{width:100%;box-sizing:border-box;margin:2px 0 6px;padding:4px 6px;border:1px solid #cbd5e1;border-radius:6px}'
        . '.help-qa-note{color:#64748b;margin:6px 0 0}'
        . '.help-qa-ask{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:10px 12px;margin-top:6px}'
        . '.help-qa-ask form{display:flex;gap:8px;align-items:center;flex-wrap:wrap}'
        . '.help-qa-ask input[name=question]{flex:1;min-width:220px}'
        . '</style>';
    if (!$qaReady && $editor) {
        $html .= '<p class="help-qa-note">Questions &amp; answers are not set up yet. After a database backup, run migrations/20261120_help_qa.sql.</p>';
    }
    if ($showList) {
    $html .= '<button type="button" class="help-qa-toggle" aria-expanded="false" aria-controls="helpQaBody" onclick="helpQaToggle(this)">Questions &amp; answers</button>';
    $html .= '<div class="help-qa-body" id="helpQaBody" hidden>';
    if (!$visible) {
        $html .= '<p class="help-qa-note">No questions on this page yet.</p>';
    }
    foreach ($visible as $row) {
        $id = (int)$row['id'];
        $roles = help_qa_json_list($row['visible_roles'] ?? '[]');
        $keywords = help_qa_json_list($row['keywords'] ?? '[]');
        $html .= '<div class="help-qa-item" draggable="' . ($editor ? 'true' : 'false') . '" data-id="' . $id . '">';
        $html .= '<div class="help-qa-q">' . $h($row['question']);
        if ((string)$row['status'] !== 'live') {
            $html .= '<span class="help-qa-flag">hidden</span>';
        }
        $feat = trim((string)($row['requires_feature'] ?? ''));
        if ($feat !== '') {
            $html .= '<span class="help-qa-flag">' . $h($feat) . (help_qa_feature_open($feat) ? ' live' : ' off') . '</span>';
        }
        $html .= '</div>';
        $html .= '<p class="help-qa-a">' . $h($row['answer']) . '</p>';
        if ($editor) {
            $html .= '<div class="help-qa-tools">';
            $html .= '<form method="post" action="' . $h($actionUrl) . '" style="display:inline">';
            $html .= '<input type="hidden" name="csrf_token" value="' . $h($csrf) . '">';
            $html .= '<input type="hidden" name="id" value="' . $id . '">';
            $html .= '<input type="hidden" name="page_key" value="' . $h($pageKey) . '">';
            $html .= '<button name="action" value="up" type="submit">Up</button> ';
            $html .= '<button name="action" value="down" type="submit">Down</button>';
            $html .= '</form>';
            $html .= '<button type="button" onclick="this.parentNode.parentNode.querySelector(\'.help-qa-edit\').classList.toggle(\'is-open\')">Edit</button>';
            $html .= '<form method="post" action="' . $h($actionUrl) . '" style="display:inline" onsubmit="return confirm(\'Delete this question?\')">';
            $html .= '<input type="hidden" name="csrf_token" value="' . $h($csrf) . '">';
            $html .= '<input type="hidden" name="id" value="' . $id . '">';
            $html .= '<input type="hidden" name="page_key" value="' . $h($pageKey) . '">';
            $html .= '<button name="action" value="delete" type="submit">Delete</button>';
            $html .= '</form>';
            $html .= '</div>';
            $html .= '<form class="help-qa-edit" method="post" action="' . $h($actionUrl) . '">';
            $html .= '<input type="hidden" name="csrf_token" value="' . $h($csrf) . '">';
            $html .= '<input type="hidden" name="action" value="save">';
            $html .= '<input type="hidden" name="id" value="' . $id . '">';
            $html .= '<input type="hidden" name="page_key" value="' . $h($pageKey) . '">';
            $html .= '<label>Question</label><input name="question" maxlength="500" required value="' . $h($row['question']) . '">';
            $html .= '<label>Answer</label><textarea name="answer" rows="5" required>' . $h($row['answer']) . '</textarea>';
            $html .= '<label>Status</label><select name="status"><option value="live"' . ((string)$row['status'] !== 'hidden' ? ' selected' : '') . '>live</option><option value="hidden"' . ((string)$row['status'] === 'hidden' ? ' selected' : '') . '>hidden</option></select>';
            $html .= '<label>Who can see it (JSON list)</label><input name="visible_roles" value="' . $h(json_encode($roles, JSON_UNESCAPED_UNICODE)) . '">';
            $html .= '<label>Keywords, comma separated</label><input name="keywords" value="' . $h(implode(', ', $keywords)) . '">';
            $html .= '<label>Requires feature (blank, t4127, or quick_entry)</label><input name="requires_feature" value="' . $h((string)($row['requires_feature'] ?? '')) . '">';
            $html .= '<label>Organization id (blank = all organizations)</label><input name="org_id" value="' . $h((string)($row['org_id'] ?? '')) . '">';
            $html .= '<button type="submit">Save</button>';
            $html .= '</form>';
        }
        $html .= '</div>';
    }
    if ($editor) {
        $html .= '<form class="help-qa-add" method="post" action="' . $h($actionUrl) . '">';
        $html .= '<input type="hidden" name="csrf_token" value="' . $h($csrf) . '">';
        $html .= '<input type="hidden" name="action" value="add">';
        $html .= '<input type="hidden" name="page_key" value="' . $h($pageKey) . '">';
        $html .= '<h6 style="margin:12px 0 6px">Add a question</h6>';
        $html .= '<label>Question</label><input name="question" maxlength="500" required>';
        $html .= '<label>Answer</label><textarea name="answer" rows="4" required></textarea>';
        $html .= '<label>Status</label><select name="status"><option value="live">live</option><option value="hidden">hidden</option></select>';
        $html .= '<label>Who can see it (JSON list)</label><input name="visible_roles" value="[&quot;superadmin&quot;]">';
        $html .= '<label>Keywords, comma separated</label><input name="keywords">';
        $html .= '<label>Requires feature</label><input name="requires_feature">';
        $html .= '<label>Organization id</label><input name="org_id">';
        $html .= '<button type="submit">Add</button>';
        $html .= '</form>';
        $html .= '<p class="help-qa-note">Drag a question onto another to reorder, or use Up and Down. Saves are marked edited in the app, so the seed will not overwrite them. <a href="help_qa.php">All questions</a></p>';
    }
        $html .= '</div>';
    }
    if ($showList) {
        $html .= '<script>
function helpQaToggle(b){var p=document.getElementById("helpQaBody");if(!p)return;var open=p.hasAttribute("hidden");if(open){p.removeAttribute("hidden");}else{p.setAttribute("hidden","");}b.setAttribute("aria-expanded",open?"true":"false");}
</script>';
    }
    if ($editor && $showList) {
        $html .= '<script>
(function(){
  var box=document.getElementById("helpQaBody"); if(!box) return;
  var drag=null;
  box.querySelectorAll(".help-qa-item").forEach(function(el){
    el.addEventListener("dragstart", function(){ drag=el; });
    el.addEventListener("dragover", function(e){ e.preventDefault(); });
    el.addEventListener("drop", function(e){
      e.preventDefault();
      if(!drag || drag===el) return;
      var items=[].slice.call(box.querySelectorAll(".help-qa-item"));
      var from=items.indexOf(drag), to=items.indexOf(el);
      if(from<0||to<0) return;
      if(from<to) el.after(drag); else el.before(drag);
      var ids=[].map.call(box.querySelectorAll(".help-qa-item"), function(n){ return n.getAttribute("data-id"); });
      var f=document.createElement("form");
      f.method="post"; f.action=' . json_encode($actionUrl) . ';
      function hid(n,v){ var i=document.createElement("input"); i.type="hidden"; i.name=n; i.value=v; f.appendChild(i); }
      hid("csrf_token", ' . json_encode($csrf) . ');
      hid("action","reorder");
      hid("page_key", ' . json_encode($pageKey) . ');
      hid("order", ids.join(","));
      document.body.appendChild(f); f.submit();
    });
  });
})();
</script>';
    }
    $html .= help_qa_ask_box($actionUrl, $csrf, $pageKey, $askNotice, $askReady);
    if ($editor) {
        $html .= help_qa_user_queue($userQuestions, $uqStatus, $actionUrl, $csrf, $pageKey, true, $askReady);
    }
    $html .= '</div>';
    return $html;
}

function help_qa_ask_box(string $actionUrl, string $csrf, string $pageKey, string $notice, bool $ready): string
{
    $h = 'help_qa_h';
    $html = '<div class="help-qa-ask">';
    $html .= '<form method="post" action="' . $h($actionUrl) . '">';
    $html .= '<input type="hidden" name="csrf_token" value="' . $h($csrf) . '">';
    $html .= '<input type="hidden" name="action" value="ask_question">';
    $html .= '<input type="hidden" name="page_key" value="' . $h($pageKey) . '">';
    $html .= '<label for="helpAskQuestion"><b>Ask a question</b></label>';
    $html .= '<input id="helpAskQuestion" name="question" maxlength="500" required placeholder="What do you want to know about this page?"' . ($ready ? '' : ' disabled') . '>';
    $html .= '<button type="submit"' . ($ready ? '' : ' disabled') . '>Send</button>';
    $html .= '</form>';
    if (!$ready) {
        $html .= '<p class="help-qa-note">Asking a question is not set up yet.</p>';
    } elseif ($notice !== '') {
        $html .= '<p class="help-qa-note">' . $h($notice) . '</p>';
    }
    $html .= '</div>';
    return $html;
}

function help_qa_user_queue(array $rows, string $status, string $actionUrl, string $csrf, string $pageKey, bool $thisPageOnly, bool $ready): string
{
    $h = 'help_qa_h';
    $html = '<div class="help-qa-ask">';
    $html .= '<b>User questions</b>';
    if (!$ready) {
        $html .= '<p class="help-qa-note">Run migrations/20261120_help_user_question.sql after a backup.</p></div>';
        return $html;
    }
    $html .= '<form method="get" style="margin:6px 0">';
    foreach ($_GET as $key => $value) {
        if ($key === 'uq_status' || is_array($value)) {
            continue;
        }
        $html .= '<input type="hidden" name="' . $h($key) . '" value="' . $h($value) . '">';
    }
    $html .= '<label>Status <select name="uq_status" onchange="this.form.submit()">';
    foreach (['new' => 'New', 'answered' => 'Answered', 'dismissed' => 'Dismissed', 'all' => 'All'] as $value => $label) {
        $html .= '<option value="' . $value . '"' . ($status === $value ? ' selected' : '') . '>' . $label . '</option>';
    }
    $html .= '</select></label></form>';
    if (!$rows) {
        $html .= '<p class="help-qa-note">No questions with that status' . ($thisPageOnly ? ' on this page' : '') . '.</p></div>';
        return $html;
    }
    foreach ($rows as $row) {
        $html .= '<div class="help-qa-item">';
        $html .= '<div class="help-qa-q">' . $h($row['question']) . ' <span class="help-qa-flag">' . $h($row['status']) . '</span></div>';
        $html .= '<p class="help-qa-note">' . $h($row['asked_by']);
        if (!$thisPageOnly) {
            $html .= ' · ' . $h($row['page_key']);
        }
        if ($row['org_id'] !== null && $row['org_id'] !== '') {
            $html .= ' · org ' . (int)$row['org_id'];
        }
        $html .= '</p>';
        if ((string)$row['status'] === 'new') {
            $html .= '<form method="post" action="' . $h($actionUrl) . '">';
            $html .= '<input type="hidden" name="csrf_token" value="' . $h($csrf) . '">';
            $html .= '<input type="hidden" name="action" value="answer_user_question">';
            $html .= '<input type="hidden" name="user_question_id" value="' . (int)$row['id'] . '">';
            $html .= '<input type="hidden" name="page_key" value="' . $h($pageKey) . '">';
            $html .= '<label>Answer</label><textarea name="answer" rows="3" required></textarea>';
            $html .= '<button type="submit">Save answer</button>';
            $html .= '</form>';
            $html .= '<form method="post" action="' . $h($actionUrl) . '" style="margin-top:6px">';
            $html .= '<input type="hidden" name="csrf_token" value="' . $h($csrf) . '">';
            $html .= '<input type="hidden" name="action" value="dismiss_user_question">';
            $html .= '<input type="hidden" name="user_question_id" value="' . (int)$row['id'] . '">';
            $html .= '<input type="hidden" name="page_key" value="' . $h($pageKey) . '">';
            $html .= '<button type="submit">Dismiss</button>';
            $html .= '</form>';
        } elseif ((string)$row['status'] === 'answered' && (int)($row['help_qa_id'] ?? 0) > 0) {
            $html .= '<p class="help-qa-note">Saved as hidden question #' . (int)$row['help_qa_id'] . '. Make it live from the list above when you want people to see it.</p>';
        }
        $html .= '</div>';
    }
    $html .= '</div>';
    return $html;
}
