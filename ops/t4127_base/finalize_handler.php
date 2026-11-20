// --- JURISDICTION ENGINE ---

// 1. Fetch site details for the shift
$site_id = $shift['site_id'];
$site_stmt = $pdo->prepare("SELECT state, country FROM sites WHERE SId = ?");
$site_stmt->execute([$site_id]);
$site_info = $site_stmt->fetch(PDO::FETCH_ASSOC);

$province = $site_info['state'] ?? 'AB';
$country  = $site_info['country'] ?? 'CA';

// 2. SET LABELS & RATES ON THE FLY
$tax_label = "EI/CPP";
$safety_label = "WCB"; // Default
$safety_rate = 0.0155; // Default (AB)

if ($country === 'CA') {
    switch ($province) {
        case 'ON':
            $safety_label = "WSIB";
            $safety_rate  = 0.0165; // Example Ontario rate
            break;
        case 'BC':
            $safety_label = "WorkSafeBC";
            $safety_rate  = 0.0225; // Example BC rate
            break;
        case 'QC':
            $safety_label = "CNESST";
            $tax_label    = "QPP/QPIP";
            break;
    }
} elseif ($country === 'US') {
    $tax_label = "FICA/FUTA";
    $safety_label = "Workers Comp";
    $safety_rate = 0.0300; // US Average Placeholder
} elseif ($country === 'AU') {
    $tax_label = "PAYG";
    $safety_label = "WorkCover";
    $safety_rate = 0.0140;
}

// 3. DO THE MATH
$gross_pay = $total_hours * $hourly_rate;
$employer_safety_cost = round($gross_pay * $safety_rate, 2);

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