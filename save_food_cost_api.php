<?php
/** Path: /save_food_cost_api.php
 * Marker: fc-del-one-20261019 + page-rollback-20261019-list — restore only ticked snapshots.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/engine/site_status_lib.php';
require_once __DIR__ . '/engine/page_rollback_lib.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Please log in.']);
    exit;
}

$fence = sip_org_site_fence($pdo, 'sip_org_id');
$is_super = !empty($fence['is_super']);
$allowed_ids = $fence['allowed_ids'] ?? [];

$action = (string)($_POST['action'] ?? '');

function fc_can_touch_site(int $rid, bool $is_super, array $allowed_ids): bool {
    if ($rid <= 0) {
        return false;
    }
    if ($is_super) {
        return empty($allowed_ids) || in_array($rid, $allowed_ids, true);
    }
    return in_array($rid, $allowed_ids, true);
}

function fc_cascade_delete(PDO $pdo, int $fcid, bool $is_super, array $allowed_ids): array {
    $st = $pdo->prepare('SELECT * FROM FoodCost WHERE FCId = ?');
    $st->execute([$fcid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'error' => 'Invoice not found.'];
    }
    $rid = (int)$row['RId'];
    if (!fc_can_touch_site($rid, $is_super, $allowed_ids)) {
        return ['ok' => false, 'error' => 'Not allowed for this restaurant.'];
    }
    $date = (string)$row['InvoiceDate'];
    $invNo = trim((string)($row['InvoiceNumber'] ?? ''));
    $file = trim((string)($row['Invoice'] ?? ''));
    $realNo = ($invNo !== '' && strtoupper($invNo) !== 'PENDING');

    /* Delete only this FCId. Same-day Sysco rows stay. */
    $invIds = [];
    if ($realNo) {
        $q = $pdo->prepare(
            'SELECT id FROM Gem_inventory_invoices
             WHERE site_id = ? AND invoice_date = ? AND invoice_number = ?'
        );
        $q->execute([$rid, $date, $invNo]);
        foreach ($q as $r) {
            $invIds[] = (int)$r['id'];
        }
    }

    $fileOthers = 0;
    if ($file !== '') {
        $c = $pdo->prepare('SELECT COUNT(*) FROM FoodCost WHERE Invoice = ? AND FCId <> ?');
        $c->execute([$file, $fcid]);
        $fileOthers = (int)$c->fetchColumn();
    }

    $orgId = 0;
    try {
        $os = $pdo->prepare('SELECT org_id FROM sites WHERE SId = ?');
        $os->execute([$rid]);
        $orgId = (int)$os->fetchColumn();
    } catch (Throwable $e) {
        $orgId = 0;
    }
    $undoId = prb_push($pdo, 'food_cost', (int)($_SESSION['user_id'] ?? 0), $orgId, $rid, 'delete', [
        'rows' => [$row],
        'files' => [],
    ]);
    $stashed = prb_stash_fc_file($undoId, __DIR__ . '/uploads/food_cost_invoices', $file);
    if ($stashed) {
        $pdo->prepare('UPDATE sip_page_rollback SET payload = ? WHERE id = ?')->execute([
            json_encode(['rows' => [$row], 'files' => [$stashed]], JSON_UNESCAPED_UNICODE),
            $undoId,
        ]);
    }

    $pdo->beginTransaction();
    $delLines = 0;
    $delInv = 0;
    if ($invIds) {
        $in = implode(',', array_map('intval', $invIds));
        $delLines = (int)$pdo->exec("DELETE FROM Gem_inventory_invoice_details WHERE invoice_id IN ($in)");
        $delInv = (int)$pdo->exec("DELETE FROM Gem_inventory_invoices WHERE id IN ($in)");
    }
    $del = $pdo->prepare('DELETE FROM FoodCost WHERE FCId = ?');
    $del->execute([$fcid]);
    $delFc = $del->rowCount();
    $pdo->commit();

    $unlinked = 0;
    if ($file !== '' && $fileOthers === 0 && $delFc > 0) {
        $root = __DIR__ . '/uploads/food_cost_invoices';
        $rootReal = realpath($root);
        $pathReal = realpath($root . '/' . ltrim(str_replace('..', '', $file), '/'));
        if ($rootReal && $pathReal && strpos($pathReal, $rootReal) === 0 && is_file($pathReal)) {
            @unlink($pathReal);
            if (is_file($pathReal . '.txt')) {
                @unlink($pathReal . '.txt');
            }
            $unlinked = 1;
        }
    }

    return [
        'ok' => true,
        'deleted_foodcost' => $delFc,
        'deleted_invoices' => $delInv,
        'deleted_lines' => $delLines,
        'unlinked_file' => $unlinked,
    ];
}

try {
    if ($action === 'add_food_cost') {
        echo json_encode(['success' => false, 'error' => 'Upload the invoice. Vendor and Amount are read from the file.']);
        exit;
    }

    if ($action === 'update_food_cost') {
        $id = (int)($_POST['id'] ?? 0);
        $field = (string)($_POST['field'] ?? '');
        $value = $_POST['value'] ?? '';
        $map = ['InvoiceDate' => 'InvoiceDate', 'Amount' => 'Amount'];
        if ($id <= 0 || !isset($map[$field])) {
            echo json_encode(['success' => false, 'error' => 'That field cannot be edited here.']);
            exit;
        }
        $chk = $pdo->prepare('SELECT RId FROM FoodCost WHERE FCId = ?');
        $chk->execute([$id]);
        $rid = (int)$chk->fetchColumn();
        if (!fc_can_touch_site($rid, $is_super, $allowed_ids)) {
            echo json_encode(['success' => false, 'error' => 'Not allowed for this restaurant.']);
            exit;
        }
        $col = $map[$field];
        if ($col === 'InvoiceDate' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$value)) {
            echo json_encode(['success' => false, 'error' => 'Bad date.']);
            exit;
        }
        if ($col === 'Vendor') {
            $value = substr((string)$value, 0, 255);
        }
        if ($col === 'Amount') {
            $value = round((float)$value, 2);
        }
        $st = $pdo->prepare("UPDATE FoodCost SET `$col` = ? WHERE FCId = ?");
        $st->execute([$value, $id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'delete_food_cost') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Bad id.']);
            exit;
        }
        $out = fc_cascade_delete($pdo, $id, $is_super, $allowed_ids);
        if (empty($out['ok'])) {
            echo json_encode(['success' => false, 'error' => $out['error'] ?? 'Delete failed.']);
            exit;
        }
        echo json_encode(['success' => true] + $out);
        exit;
    }

    if ($action === 'set_rollback_days') {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $lvl = (int)($_SESSION['user_level'] ?? 99);
        if ($uid <= 0 || $lvl > 20) {
            echo json_encode(['success' => false, 'error' => 'Rollback days is for admin or super.']);
            exit;
        }
        $orgId = (int)($_POST['org_id'] ?? 0);
        $siteId = (int)($_POST['site_id'] ?? 0);
        if ($orgId <= 0 && $siteId > 0) {
            $orgId = prb_org_for_site($pdo, $siteId);
        }
        if ($orgId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Pick an organization or restaurant first.']);
            exit;
        }
        if ($siteId > 0 && !fc_can_touch_site($siteId, $is_super, $allowed_ids)) {
            echo json_encode(['success' => false, 'error' => 'Not allowed for this restaurant.']);
            exit;
        }
        $days = prb_set_org_days($pdo, $orgId, (int)($_POST['days'] ?? 7), $uid);
        echo json_encode(['success' => true, 'days' => $days, 'org_id' => $orgId]);
        exit;
    }

    if ($action === 'rollback_food_cost') {
        $siteFilter = (int)($_POST['site_id'] ?? 0);
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $lvl = (int)($_SESSION['user_level'] ?? 99);
        if ($uid <= 0 || $lvl > 20) {
            echo json_encode(['success' => false, 'error' => 'Rollback is for admin or super.']);
            exit;
        }
        if ($siteFilter <= 0 || !fc_can_touch_site($siteFilter, $is_super, $allowed_ids)) {
            echo json_encode(['success' => false, 'error' => 'Pick that restaurant. Rollback does not cross sites.']);
            exit;
        }
        $ids = prb_request_ids();
        if (!$ids) {
            echo json_encode(['success' => false, 'error' => 'Tick the invoice(s) to restore.']);
            exit;
        }
        $undos = prb_by_ids($pdo, 'food_cost', $siteFilter, $ids);
        if (!$undos) {
            echo json_encode(['success' => false, 'error' => 'Those deletes are gone or expired.']);
            exit;
        }
        $restored = 0;
        $used = [];
        $root = __DIR__ . '/uploads/food_cost_invoices';
        foreach ($undos as $undo) {
            $payload = json_decode((string)$undo['payload'], true);
            $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
            $n = 0;
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rid = (int)($row['RId'] ?? 0);
                if ($rid <= 0 || !fc_can_touch_site($rid, $is_super, $allowed_ids)) {
                    continue;
                }
                if ($siteFilter > 0 && $rid !== $siteFilter) {
                    continue;
                }
                $fcid = (int)($row['FCId'] ?? 0);
                $exists = false;
                if ($fcid > 0) {
                    $chk = $pdo->prepare('SELECT FCId FROM FoodCost WHERE FCId = ?');
                    $chk->execute([$fcid]);
                    $exists = (bool)$chk->fetchColumn();
                }
                if ($exists) {
                    unset($row['FCId']);
                }
                if (prb_insert_assoc($pdo, 'FoodCost', $row)) {
                    $n++;
                    $rel = (string)($row['Invoice'] ?? '');
                    $stashName = (string)(($payload['files'][0] ?? '') ?: basename($rel));
                    prb_restore_fc_file((int)$undo['id'], $root, $rel, $stashName !== '' ? $stashName : null);
                }
            }
            if ($n > 0) {
                prb_mark_used($pdo, (int)$undo['id']);
                $restored += $n;
                $used[] = (int)$undo['id'];
            }
        }
        if ($restored < 1) {
            echo json_encode(['success' => false, 'error' => 'Could not restore those invoices.']);
            exit;
        }
        echo json_encode(['success' => true, 'restored' => $restored, 'ids' => $used]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action.']);
} catch (Throwable $e) {
    error_log('save_food_cost_api: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Save failed.']);
}
