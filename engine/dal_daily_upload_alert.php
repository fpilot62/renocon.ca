<?php
/**
 * Corner flag when today's POS, Production, or Paid Out upload is missing.
 * Path: /engine/dal_daily_upload_alert.php
 */

if (!function_exists('sip_dal_business_date')) {
    function sip_dal_business_date(): string
    {
        $tz = new DateTimeZone('America/Vancouver');
        $now = new DateTime('now', $tz);
        // POS day rolls at 03:30. Before 4am, yesterday is still the day to upload.
        if ((int)$now->format('G') < 4) {
            $now->modify('-1 day');
        }
        return $now->format('Y-m-d');
    }
}

if (!function_exists('sip_dal_kind_uploaded')) {
    function sip_dal_kind_uploaded(string $root, int $rid, string $date, string $kind): bool
    {
        $ym = substr($date, 0, 7);
        foreach (['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif'] as $ext) {
            if (is_file($root . '/' . $rid . '/' . $ym . '/' . $date . '_' . $kind . '_report.' . $ext)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('sip_dal_supervisor_sites')) {
    function sip_dal_supervisor_sites(PDO $pdo): array
    {
        $sites = $_SESSION['inventory_sites'] ?? [];
        $out = [];
        foreach ($sites as $s) {
            $id = (int)($s['id'] ?? $s['SId'] ?? 0);
            $name = (string)($s['nick_name'] ?? $s['name'] ?? '');
            if ($id > 0) {
                $out[$id] = $name !== '' ? $name : ('Site ' . $id);
            }
        }
        if ($out) {
            return $out;
        }
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) {
            return [];
        }
        $st = $pdo->prepare("SELECT primary_site_id, primary_restaurant_id, assigned_site_ids FROM users WHERE id = ?");
        $st->execute([$uid]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u) {
            return [];
        }
        $ids = [];
        foreach ([(int)$u['primary_site_id'], (int)$u['primary_restaurant_id']] as $id) {
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        foreach (explode(',', (string)$u['assigned_site_ids']) as $p) {
            $id = (int)trim($p);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_map('intval', array_keys($ids)));
        foreach ($pdo->query("SELECT SId, nick_name FROM sites WHERE SId IN ($in)") as $row) {
            $out[(int)$row['SId']] = $row['nick_name'];
        }
        return $out;
    }
}

if (!function_exists('sip_dal_missing_upload_alert')) {
    function sip_dal_missing_upload_alert(PDO $pdo): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $lvl = (int)($_SESSION['user_level'] ?? $_SESSION['user_role'] ?? 99);
        // Site Manager 25 and Supervisor 30 — the people who run the store.
        if ($lvl < 25 || $lvl > 30) {
            return '';
        }
        $sites = sip_dal_supervisor_sites($pdo);
        if (!$sites) {
            return '';
        }
        $date = sip_dal_business_date();
        $root = dirname(__DIR__) . '/uploads/daily_reports';
        $kinds = ['pos' => 'POS', 'production' => 'Production', 'paidout' => 'Paid Out'];
        $items = [];
        foreach ($sites as $rid => $name) {
            $missing = [];
            foreach ($kinds as $kind => $label) {
                if (!sip_dal_kind_uploaded($root, (int)$rid, $date, $kind)) {
                    $missing[] = $label;
                }
            }
            if ($missing) {
                $href = '/daily_report_view.php?' . http_build_query([
                    'rid' => (int)$rid,
                    'start' => $date,
                    'end' => $date,
                ]);
                $items[] = [
                    'name' => $name,
                    'missing' => $missing,
                    'href' => $href,
                ];
            }
        }
        if (!$items) {
            return '';
        }
        $n = count($items);
        $dateLabel = date('M j', strtotime($date));
        $flagLabel = $n === 1 ? '1 upload due' : ($n . ' uploads due');
        $rows = '';
        foreach ($items as $item) {
            $rows .= '<a class="dal-miss-row" href="' . htmlspecialchars($item['href']) . '">'
                . '<span class="dal-miss-name">' . htmlspecialchars($item['name']) . '</span>'
                . '<span class="dal-miss-miss">' . htmlspecialchars(implode(', ', $item['missing'])) . '</span>'
                . '<span class="dal-miss-go">Upload</span>'
                . '</a>';
        }
        $nJson = (int)$n;
        $dateJson = json_encode($date);
        return <<<HTML
<style>
.dal-miss-flag{position:fixed;right:16px;bottom:16px;z-index:9988;font-family:Inter,system-ui,sans-serif}
.dal-miss-btn{display:inline-flex;align-items:center;gap:8px;border:0;cursor:pointer;background:#b91c1c;color:#fff;border-radius:999px;padding:10px 14px;font-size:13px;font-weight:800;box-shadow:0 8px 22px rgba(127,29,29,.35)}
.dal-miss-btn:hover{filter:brightness(1.06)}
.dal-miss-dot{width:8px;height:8px;border-radius:50%;background:#fecaca;box-shadow:0 0 0 4px rgba(254,202,202,.25)}
.dal-miss-panel{display:none;position:absolute;right:0;bottom:52px;width:min(340px,calc(100vw - 24px));background:#fff;color:#111827;border-radius:14px;box-shadow:0 16px 40px rgba(15,23,42,.28);overflow:hidden;border:1px solid #fecaca}
.dal-miss-flag.is-open .dal-miss-panel{display:block}
.dal-miss-head{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:10px 12px;background:#fef2f2;border-bottom:1px solid #fecaca}
.dal-miss-head strong{font-size:13px;color:#991b1b}
.dal-miss-head span{display:block;font-size:11px;font-weight:600;color:#b91c1c}
.dal-miss-x{border:0;background:transparent;color:#7f1d1d;font-size:20px;line-height:1;cursor:pointer;padding:0 4px}
.dal-miss-list{max-height:240px;overflow:auto}
.dal-miss-row{display:grid;grid-template-columns:1fr auto;grid-template-rows:auto auto;gap:0 10px;padding:10px 12px;text-decoration:none;border-bottom:1px solid #fee2e2}
.dal-miss-row:last-child{border-bottom:0}
.dal-miss-row:hover{background:#fff7ed}
.dal-miss-name{font-size:13px;font-weight:700;color:#111827}
.dal-miss-miss{grid-column:1;font-size:12px;color:#b91c1c;font-weight:600}
.dal-miss-go{grid-row:1 / span 2;align-self:center;font-size:11px;font-weight:800;color:#fff;background:#b91c1c;border-radius:999px;padding:5px 9px}
.dal-miss-foot{padding:8px 12px;font-size:11px;color:#6b7280;background:#f9fafb}
</style>
<div class="dal-miss-flag" id="dalMissFlag">
  <div class="dal-miss-panel" id="dalMissPanel" role="dialog" aria-label="Daily uploads due">
    <div class="dal-miss-head">
      <div>
        <strong>Daily uploads due</strong>
        <span>{$dateLabel} · POS, Production, Paid Out</span>
      </div>
      <button type="button" class="dal-miss-x" id="dalMissClose" aria-label="Close">×</button>
    </div>
    <div class="dal-miss-list">{$rows}</div>
    <div class="dal-miss-foot">Flag stays until all three files are on the Daily Activity Log.</div>
  </div>
  <button type="button" class="dal-miss-btn" id="dalMissBtn" aria-expanded="false">
    <span class="dal-miss-dot" aria-hidden="true"></span>
    {$flagLabel}
  </button>
</div>
<script>
(function(){
  var root = document.getElementById("dalMissFlag");
  if (!root) return;
  var btn = document.getElementById("dalMissBtn");
  var closeBtn = document.getElementById("dalMissClose");
  var key = "dalMissFlagOpen:" + {$dateJson} + ":" + {$nJson};
  function setOpen(on){
    root.classList.toggle("is-open", on);
    if (btn) btn.setAttribute("aria-expanded", on ? "true" : "false");
    try { sessionStorage.setItem(key, on ? "1" : "0"); } catch (e) {}
  }
  if (btn) btn.addEventListener("click", function(){ setOpen(!root.classList.contains("is-open")); });
  if (closeBtn) closeBtn.addEventListener("click", function(){ setOpen(false); });
  document.addEventListener("click", function(e){
    if (!root.contains(e.target)) setOpen(false);
  });
  try { if (sessionStorage.getItem(key) === "1") setOpen(true); } catch (e) {}
})();
</script>
HTML;
    }
}
