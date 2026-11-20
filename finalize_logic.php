// Assuming $site contains the data for the site the staff worked at
$country = $site['country'] ?? 'CA';
$province = $site['state'] ?? 'AB';

// DEFAULT LABELS & RATES (Alberta Baseline)
$tax_label = "EI/CPP";
$safety_label = "WCB"; // Workers Comp Label
$vacation_rate = 0.04; // 4% Standard

// JURISDICTION ROUTING
switch ($province) {
    case 'ON':
        $safety_label = "WSIB"; // Ontario specific
        // You can add specific Ontario rates here
        break;
    
    case 'BC':
        $safety_label = "WCB (BC)";
        // BC has different Stat Holiday rules, we can flag that here too
        break;

    case 'NY':
        $tax_label = "FICA/FUTA";
        $safety_label = "NYSIF";
        break;

    case 'NSW': // Australia
        $tax_label = "PAYG";
        $safety_label = "WorkCover";
        break;

    default:
        $safety_label = "WCB";
        $tax_label = "EI/CPP";
        break;
}