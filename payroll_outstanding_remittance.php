<?php
/**
 * Path: /payroll_outstanding_remittance.php
 *
 * TWO REPORTS ON ONE PAGE, driven by the SAME filters as payroll_master_ledger.php:
 *   1) OUTSTANDING PAYMENTS DUE — what you still owe employees (Site > Employee).
 *   2) CRA REMITTANCE — source deductions owed to CRA (income tax + CPP & EI, both sides).
 *   3) WCB — shown SEPARATELY because it goes to the provincial board, NOT to CRA.
 *
 * All figures READ the STORED columns on payroll_finalized (cpp, ei, income_tax, cpp_er,
 * ei_er, wcb, vac). Nothing is recalculated, so this always agrees with the ledger,
 * the paystubs, and (later) the T4s.
 */
ini_set('display_errors', 1);
error_reporting(E_ALL & ~E_DEPRECATED);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
require_once 'payroll_scope.php';
if (file_exists('engine/menu_generator.php')) { require_once 'engine/menu_generator.php'; }

ps_require_payroll_admin();
$org_id = ps_scope_org_id();

// ---- FILTERS (same param names as the ledger, so links carry across) ----------
$from_date       = $_GET['from_date'] ?? date('Y-m-01');
$to_date         = $_GET['to_date']   ?? date('Y-m-t');
$site_filter     = $_GET['site_id']   ?? 'all';
$employee_filter = $_GET['user_id']   ?? 'all';
$scope           = $_GET['scope']     ?? 'outstanding';   // outstanding | all
if ($site_filter !== 'all' && $site_filter !== '') { ps_assert_site($pdo, (int)$site_filter, $org_id); }
if ($employee_filter !== 'all' && $employee_filter !== '') { ps_assert_user($pdo, (int)$employee_filter, $org_id); }

function ee_total(array $r): float { return (float)$r['ee_cpp'] + (float)$r['ee_ei'] + (float)$r['ee_tax']; }
function net_line(array $r): float { return round((float)$r['total_gross'] - ee_total($r), 2); }
function money($v){ $v=(float)$v; return ($v<0?'-$':'$').number_format(abs($v),2); }

$where  = ["f.work_date BETWEEN ? AND ?"];
$params = [$from_date, $to_date];
ps_apply_org_fence($where, $params, $org_id);
if ($site_filter !== 'all' && $site_filter !== '')         { $where[] = "f.site_id = ?"; $params[] = (int)$site_filter; }
if ($employee_filter !== 'all' && $employee_filter !== '') { $where[] = "f.user_id = ?"; $params[] = (int)$employee_filter; }

$sql = "SELECT f.id AS line_id, f.user_id, f.site_id, f.work_date, f.total_gross,
               f.cpp AS ee_cpp, f.ei AS ee_ei, f.income_tax AS ee_tax,
               f.cpp_er AS er_cpp, f.ei_er AS er_ei, f.wcb AS er_wcb, f.vac AS er_vac,
               f.paid_amount, f.payment_status,
               u.Name AS employee_name,
               COALESCE(s.nick_name,'Unassigned') AS site_name,
               s.corp_legal_name, s.province
        FROM payroll_finalized f
        INNER JOIN users u ON f.user_id = u.id
        LEFT  JOIN sites s ON f.site_id = s.Sid
        WHERE " . implode(" AND ", $where) . "
        ORDER BY s.nick_name ASC, u.Name ASC, f.work_date ASC";
$st = $pdo->prepare($sql); $st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$sites=[]; $cra=['ee_tax'=>0,'ee_cpp'=>0,'ee_ei'=>0,'er_cpp'=>0,'er_ei'=>0];
$cra_site=[]; $wcb_site=[];
$grand=['gross'=>0,'ee'=>0,'net'=>0,'paid'=>0,'out'=>0,'vac'=>0];

foreach ($rows as $r) {
    $sid=(int)$r['site_id']; $uid=(int)$r['user_id'];
    $net=net_line($r); $paid=(float)$r['paid_amount']; $out=round($net-$paid,2);
    if(!isset($sites[$sid])){
        $sites[$sid]=['name'=>$r['site_name'],'corp'=>$r['corp_legal_name']?:'','prov'=>$r['province']?:'',
                      'emps'=>[], 't'=>['gross'=>0,'ee'=>0,'net'=>0,'paid'=>0,'out'=>0]];
        $cra_site[$sid]=['ee_tax'=>0,'ee_cpp'=>0,'ee_ei'=>0,'er_cpp'=>0,'er_ei'=>0];
        $wcb_site[$sid]=0.0;
    }
    if(!isset($sites[$sid]['emps'][$uid])){
        $sites[$sid]['emps'][$uid]=['name'=>$r['employee_name'],'lines'=>0,'gross'=>0,'ee'=>0,'net'=>0,'paid'=>0,'out'=>0];
    }
    $e=&$sites[$sid]['emps'][$uid];
    $e['lines']++; $e['gross']+=(float)$r['total_gross']; $e['ee']+=ee_total($r);
    $e['net']+=$net; $e['paid']+=$paid; $e['out']+=$out; unset($e);

    $T=&$sites[$sid]['t'];
    $T['gross']+=(float)$r['total_gross']; $T['ee']+=ee_total($r);
    $T['net']+=$net; $T['paid']+=$paid; $T['out']+=$out; unset($T);

    foreach(['ee_tax','ee_cpp','ee_ei','er_cpp','er_ei'] as $k){
        $cra_site[$sid][$k]+=(float)$r[$k]; $cra[$k]+=(float)$r[$k];
    }
    $wcb_site[$sid]+=(float)$r['er_wcb'];

    $grand['gross']+=(float)$r['total_gross']; $grand['ee']+=ee_total($r);
    $grand['net']+=$net; $grand['paid']+=$paid; $grand['out']+=$out; $grand['vac']+=(float)$r['er_vac'];
}
$wcb_total=array_sum($wcb_site);
$cra_total=$cra['ee_tax']+$cra['ee_cpp']+$cra['ee_ei']+$cra['er_cpp']+$cra['er_ei'];

$site_list  = ps_list_sites($pdo, $org_id);
$staff_list = ps_list_users($pdo, $org_id);
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><title>Outstanding Pay &amp; CRA Remittance</title>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
 body{background:#f1f5f9;font-family:'Inter',system-ui,sans-serif;font-size:13px;color:#1e293b;padding-bottom:60px;}
 .wrap{max-width:1220px;margin:0 auto;padding:22px 16px;}
 h2{font-weight:800;color:#0f172a;font-size:21px;margin:0;}
 .sub{color:#64748b;font-size:12px;}
 .panel{background:#fff;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:20px;box-shadow:0 2px 6px rgba(0,0,0,.03);overflow:hidden;}
 .sec{font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;padding:11px 14px;background:#0f172a;color:#fff;}
 table{width:100%;border-collapse:collapse;}
 th{background:#475569;color:#fff;font-size:10px;text-transform:uppercase;padding:9px;text-align:right;}
 th.l{text-align:left;} td{padding:8px 9px;border-bottom:1px solid #e2e8f0;text-align:right;} td.l{text-align:left;}
 tr.site td{background:#e2e8f0;font-weight:800;}
 tr.tot td{background:#0f172a;color:#fff;font-weight:800;}
 .pos{color:#dc2626;font-weight:700;} .zero{color:#16a34a;font-weight:700;} .credit{color:#2563eb;font-weight:700;}
 .kpi{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:18px;}
 .kpi .b{flex:1;min-width:170px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:13px 15px;}
 .kpi .b .l{font-size:10px;text-transform:uppercase;color:#64748b;font-weight:700;}
 .kpi .b .v{font-size:19px;font-weight:800;margin-top:3px;}
 @media print{.noprint,nav,header{display:none!important;} body{background:#fff;} .panel{box-shadow:none;border:1px solid #999;page-break-inside:avoid;}}
</style></head><body>
<?php if (function_exists('generateMenu')) echo generateMenu($pdo); ?>
<div class="wrap">
<?php if (file_exists(__DIR__.'/payroll_help.php')) { require_once __DIR__.'/payroll_help.php'; echo payroll_help_panel('payroll_outstanding_remittance.php'); } ?>
 <div class="d-flex justify-content-between align-items-start mb-3">
  <div>
   <h2><i class="fa fa-file-invoice-dollar text-primary"></i> Outstanding Pay &amp; CRA Remittance</h2>
   <p class="text-muted small mb-1" style="max-width:860px;">Shows what is still owed to <strong>staff (net after EE deductions)</strong> and what is owed to <strong>CRA</strong> (income tax + CPP EE/ER + EI EE/ER) and <strong>WCB</strong> (provincial board, not CRA). Tax and net are non-zero only after Finalize — imported hours without finalize show $0 tax. August 2026 is empty if pay is July 13–26; set that date range. Not the QB gross export page (Daily Payroll Summary).</p>
   <div class="sub"><?=htmlspecialchars($from_date)?> to <?=htmlspecialchars($to_date)?></div>
  </div>
  <div class="noprint">
   <a href="payroll_master_ledger.php?from_date=<?=urlencode($from_date)?>&to_date=<?=urlencode($to_date)?>&site_id=<?=urlencode($site_filter)?>&user_id=<?=urlencode($employee_filter)?>" class="btn btn-sm btn-outline-secondary font-weight-bold"><i class="fa fa-table"></i> Ledger</a>
   <button onclick="window.print()" class="btn btn-sm btn-dark font-weight-bold"><i class="fa fa-print"></i> Print</button>
  </div>
 </div>

 <div class="panel noprint" style="padding:15px 16px;">
  <form method="GET" class="form-row align-items-end mb-0">
   <div class="col-md-2 mb-2"><label class="small font-weight-bold text-muted text-uppercase mb-1">From</label>
    <input type="date" name="from_date" class="form-control form-control-sm font-weight-bold" value="<?=htmlspecialchars($from_date)?>"></div>
   <div class="col-md-2 mb-2"><label class="small font-weight-bold text-muted text-uppercase mb-1">To</label>
    <input type="date" name="to_date" class="form-control form-control-sm font-weight-bold" value="<?=htmlspecialchars($to_date)?>"></div>
   <div class="col-md-3 mb-2"><label class="small font-weight-bold text-muted text-uppercase mb-1">Site (employer)</label>
    <select name="site_id" class="form-control form-control-sm font-weight-bold"><option value="all">All sites</option>
    <?php foreach($site_list as $s): ?><option value="<?=$s['Sid']?>" <?=((string)$site_filter===(string)$s['Sid'])?'selected':''?>><?=htmlspecialchars($s['nick_name'])?></option><?php endforeach; ?>
    </select></div>
   <div class="col-md-3 mb-2"><label class="small font-weight-bold text-muted text-uppercase mb-1">Employee</label>
    <select name="user_id" class="form-control form-control-sm font-weight-bold"><option value="all">All staff</option>
    <?php foreach($staff_list as $e): ?><option value="<?=$e['id']?>" <?=((string)$employee_filter===(string)$e['id'])?'selected':''?>><?=htmlspecialchars($e['Name'])?></option><?php endforeach; ?>
    </select></div>
   <div class="col-md-2 mb-2"><label class="small font-weight-bold text-muted text-uppercase mb-1">Show</label>
    <select name="scope" class="form-control form-control-sm font-weight-bold">
     <option value="outstanding" <?=$scope==='outstanding'?'selected':''?>>Only unpaid</option>
     <option value="all" <?=$scope==='all'?'selected':''?>>All rows</option></select></div>
   <div class="col-md-12"><button class="btn btn-sm btn-primary font-weight-bold px-4"><i class="fa fa-sync"></i> Run Report</button></div>
  </form>
 </div>

 <div class="kpi">
  <div class="b"><div class="l">Total gross</div><div class="v"><?=money($grand['gross'])?></div></div>
  <div class="b"><div class="l">Employee net</div><div class="v"><?=money($grand['net'])?></div></div>
  <div class="b"><div class="l">Paid so far</div><div class="v" style="color:#16a34a;"><?=money($grand['paid'])?></div></div>
  <div class="b"><div class="l">Still owed to staff</div><div class="v" style="color:#dc2626;"><?=money($grand['out'])?></div></div>
  <div class="b"><div class="l">CRA remittance</div><div class="v"><?=money($cra_total)?></div></div>
 </div>

 <!-- 1. OUTSTANDING -->
 <div class="panel">
  <div class="sec"><i class="fa fa-hand-holding-dollar"></i> 1. Outstanding payments due to employees</div>
  <table>
   <thead><tr><th class="l">Site / Employee</th><th>Lines</th><th>Gross</th><th>EE deductions</th><th>Net owed</th><th>Paid</th><th>Still outstanding</th></tr></thead>
   <tbody>
   <?php if(empty($sites)): ?><tr><td colspan="7" class="l text-muted py-4">No payroll rows in this range.</td></tr>
   <?php else: foreach($sites as $sid=>$S):
     $emps=$S['emps'];
     if($scope==='outstanding') $emps=array_filter($emps,function($e){return abs($e['out'])>0.01;});
     if(empty($emps)) continue;
     $sout=0; foreach($emps as $e) $sout+=$e['out'];
   ?>
    <tr class="site"><td class="l"><i class="fa fa-building"></i> <?=htmlspecialchars($S['name'])?>
      <?php if($S['corp']): ?><span class="sub" style="font-weight:600;"> · <?=htmlspecialchars($S['corp'])?></span><?php endif; ?></td>
     <td></td><td><?=money($S['t']['gross'])?></td><td><?=money($S['t']['ee'])?></td>
     <td><?=money($S['t']['net'])?></td><td><?=money($S['t']['paid'])?></td>
     <td class="<?=$sout>0.01?'pos':($sout<-0.01?'credit':'zero')?>"><?=money($sout)?></td></tr>
    <?php foreach($emps as $uid=>$e): ?>
     <tr><td class="l" style="padding-left:30px;"><i class="fa fa-user text-muted"></i> <?=htmlspecialchars($e['name'])?></td>
      <td><?=$e['lines']?></td><td><?=money($e['gross'])?></td><td><?=money($e['ee'])?></td>
      <td><?=money($e['net'])?></td><td style="color:#16a34a;"><?=money($e['paid'])?></td>
      <td class="<?=$e['out']>0.01?'pos':($e['out']<-0.01?'credit':'zero')?>"><?=money($e['out'])?>
      <?php if($e['out']<-0.01): ?><br><span class="sub" style="font-size:9px;">CREDIT (overpaid)</span><?php endif; ?></td></tr>
    <?php endforeach; ?>
   <?php endforeach; endif; ?>
    <tr class="tot"><td class="l">TOTAL OUTSTANDING TO STAFF</td><td></td><td><?=money($grand['gross'])?></td>
     <td><?=money($grand['ee'])?></td><td><?=money($grand['net'])?></td><td><?=money($grand['paid'])?></td>
     <td><?=money($grand['out'])?></td></tr>
   </tbody></table>
 </div>

 <!-- 2. CRA REMITTANCE -->
 <div class="panel" style="border-left:5px solid #0f172a;">
  <div class="sec"><i class="fa fa-landmark"></i> 2. Source deductions — CRA remittance</div>
  <table>
   <thead><tr><th class="l">Site (legal employer)</th><th>Income tax (EE)</th><th>CPP (EE)</th><th>CPP (ER)</th><th>EI (EE)</th><th>EI (ER)</th><th>CRA total</th></tr></thead>
   <tbody>
   <?php if(empty($sites)): ?><tr><td colspan="7" class="l text-muted py-4">Nothing to remit.</td></tr>
   <?php else: foreach($sites as $sid=>$S): $c=$cra_site[$sid];
     $ct=$c['ee_tax']+$c['ee_cpp']+$c['ee_ei']+$c['er_cpp']+$c['er_ei']; ?>
    <tr><td class="l"><strong><?=htmlspecialchars($S['name'])?></strong>
      <?php if($S['corp']): ?><div class="sub"><?=htmlspecialchars($S['corp'])?></div><?php endif; ?></td>
     <td><?=money($c['ee_tax'])?></td><td><?=money($c['ee_cpp'])?></td><td><?=money($c['er_cpp'])?></td>
     <td><?=money($c['ee_ei'])?></td><td><?=money($c['er_ei'])?></td><td style="font-weight:800;"><?=money($ct)?></td></tr>
   <?php endforeach; endif; ?>
    <tr class="tot"><td class="l">TOTAL DUE TO CRA</td><td><?=money($cra['ee_tax'])?></td><td><?=money($cra['ee_cpp'])?></td>
     <td><?=money($cra['er_cpp'])?></td><td><?=money($cra['ee_ei'])?></td><td><?=money($cra['er_ei'])?></td>
     <td><?=money($cra_total)?></td></tr>
   </tbody></table>
  <div style="padding:9px 14px;background:#f8fafc;border-top:1px solid #e2e8f0;" class="sub">
   CRA remittance = income tax withheld + CPP (employee + employer) + EI (employee + employer).
   Each site is its own legal employer (own CRA business number) — remit per site.</div>
 </div>

 <!-- 3. WCB -->
 <div class="panel" style="border-left:5px solid #b45309;">
  <div class="sec" style="background:#b45309;"><i class="fa fa-hard-hat"></i> 3. WCB / WorkSafe premiums
   <span style="font-weight:600;text-transform:none;letter-spacing:0;">— paid to the provincial board, NOT to CRA</span></div>
  <table><thead><tr><th class="l">Site</th><th>Province</th><th>WCB premium</th></tr></thead><tbody>
  <?php foreach($sites as $sid=>$S): ?>
   <tr><td class="l"><?=htmlspecialchars($S['name'])?></td><td><?=htmlspecialchars($S['prov']?:'—')?></td>
    <td><?=money($wcb_site[$sid])?></td></tr>
  <?php endforeach; ?>
   <tr class="tot"><td class="l">TOTAL WCB</td><td></td><td><?=money($wcb_total)?></td></tr>
  </tbody></table>
 </div>

 <div class="panel sub" style="padding:12px 15px;">
  <strong>Accuracy:</strong> every figure is read from the values stored on each payroll line at finalize time
  (not recalculated), so this report always agrees with the ledger, paystubs and T4s.
  Vacation accrued in this range: <strong><?=money($grand['vac'])?></strong> (a liability owed to staff, for reference).
 </div>
</div></body></html>