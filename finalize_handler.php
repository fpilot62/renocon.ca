// --- JURISDICTION ENGINE ---

// 1. Fetch site details for the shift
$site_id = $shift['site_id'];
$site_stmt = $pdo->prepare("SELECT state, country FROM sites WHERE SId = ?");
$site_stmt->execute([$site_id]);
$site_info = $site_stmt->fetch(PDO::FETCH_ASSOC);

$province = $site_info['state'] ?? 'AB';
$country  = $site_info['country'] ?? 'CA';

$tax_label = "EI/CPP";
$safety_label = "WCB";
if ($country === 'CA' && $province === 'ON') { $safety_label = "WSIB"; }
if ($country === 'CA' && $province === 'BC') { $safety_label = "WorkSafeBC"; }
if ($country === 'CA' && $province === 'QC') { $tax_label = "QPP/QPIP"; $safety_label = "CNESST"; }
if ($country === 'US') { $tax_label = "FICA/FUTA"; $safety_label = "Workers Comp"; }
if ($country === 'AU') { $tax_label = "PAYG"; $safety_label = "WorkCover"; }

if (!function_exists('pe_line')) { require_once __DIR__ . '/payroll_engine/payroll_engine.php'; }
if (!function_exists('sh_after_finalize')) { require_once __DIR__ . '/payroll_engine/stat_holidays.php'; }
$gross_pay = $total_hours * $hourly_rate;
$day = substr((string)$work_date, 0, 10);
$fin = pe_line($gross_pay, pe_vacation_rate($pdo, (int)$user_id, (int)$site_id, $day), pe_rates($pdo), [
    'pdo' => $pdo, 'user_id' => (int)$user_id, 'site_id' => (int)$site_id, 'org_id' => (int)($shift['org_id'] ?? 0), 'work_date' => $day,
]);
$employer_safety_cost = (float)$fin['wcb'];
sh_after_finalize($pdo, (int)$user_id, (int)$site_id, $day, 0);

// 4. SAVE TO payroll_finalized
// We now save the specific label so the Pay Stub is 100% accurate
$ins = $pdo->prepare("INSERT INTO payroll_finalized (
    user_id, work_date, reg_pay, safety_contribution, safety_label, tax_label, country_code
) VALUES (?, ?, ?, ?, ?, ?, ?)");

$ins->execute([
    $user_id, 
    $work_date, 
    $gross_pay, 
    $employer_safety_cost, 
    $safety_label, 
    $tax_label,
    $country
]);