<?php
/** Path: /help_center.php 
 * ==============================================================================
 * 🛡️ THE GOLDEN RULES - DO NOT DELETE OR MODIFY THIS SECTION 🛡️
 * ==============================================================================
 * 1. HIERARCHY: SuperAdmin <= 10, Admins 11-30, Staff > 30.
 * 2. NAVIGATION: Adaptive role-based cards with anchor links.
 * ==============================================================================
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
require_once 'engine/MENU_MIN_ROLE.php';
require_once 'engine/menu_generator.php';
require_once 'payroll_help.php';

// --- 1. SECURITY CHECK ---
if (!isset($_SESSION['user_id'])) { 
    header("Location: login.php"); 
    exit; 
}

$user_level = (int)($_SESSION['user_level'] ?? 99);
$user_name  = $_SESSION['user_name'] ?? 'Team Member';

// Define Role Flags for UI
$is_super = ($user_level <= 10);
$is_admin = ($user_level > 10 && $user_level <= 30);
$is_staff = ($user_level > 30);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SIP Global | Learning Center</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --sip-dark: #0f172a; --sip-blue: #2563eb; --sip-bg: #f1f5f9; }
        body { font-family: 'Inter', sans-serif; background: var(--sip-bg); padding-top: 100px; color: #334155; }
        
        /* Header Section */
        .help-hero { background: var(--sip-dark); color: white; padding: 50px 0; border-radius: 0 0 40px 40px; margin-bottom: 40px; }
        
        /* Navigation Sidebar/Pills */
        .nav-link { color: #64748b; font-weight: 600; border-radius: 8px; margin-bottom: 5px; }
        .nav-link.active { background: var(--sip-blue) !important; color: white !important; }
        .nav-link:hover { background: #e2e8f0; }

        /* Tutorial Cards */
        .doc-card { background: white; border: none; border-radius: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.03); margin-bottom: 30px; transition: 0.3s; }
        .doc-card:hover { transform: translateY(-5px); box-shadow: 0 15px 30px rgba(0,0,0,0.07); }
        .icon-circle { width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 24px; margin-bottom: 20px; }
        
        /* Role Badges */
        .role-badge { font-size: 10px; font-weight: 900; letter-spacing: 1px; text-transform: uppercase; padding: 5px 12px; border-radius: 50px; display: inline-block; margin-bottom: 15px; }
        
        code { background: #f1f5f9; color: #be185d; padding: 2px 6px; border-radius: 4px; font-weight: 600; }
        .step-num { width: 24px; height: 24px; background: var(--sip-blue); color: white; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; margin-right: 10px; }
    </style>
</head>
<body>

<?= generateMenu($pdo); ?>

<?php if ($is_super): ?>
<p class="container"><a href="help_inbox.php" style="font-weight:800;">Ask AI inbox</a> — questions the guide could not answer.</p>
<?php endif; ?>
<div class="help-hero text-center">
    <div class="container">
        <h1 class="display-4 font-weight-bold">Learning Center</h1>
        <p class="lead opacity-75">Welcome, <?= htmlspecialchars($user_name) ?>. Select a tutorial to begin.</p>
    </div>
</div>

<div class="container">
    <div class="row">
        <div class="col-md-3">
            <div class="sticky-top" style="top: 120px;">
                <h6 class="text-uppercase font-weight-bold small text-muted mb-3">Quick Navigation</h6>
                <nav class="nav flex-column nav-pills nav-pill-custom">
                    <a class="nav-link" href="ms-quick-assist://">Quick Assist</a>
                    <a class="nav-link active" href="#overview">System Overview</a>
                    <a class="nav-link" href="#page-guides">Written page guides</a>
                    <a class="nav-link" href="#staff">Staff Tutorial</a>
                    <?php if ($user_level <= 30): ?>
                        <a class="nav-link" href="#manager">Manager Tutorial</a>
                    <?php endif; ?>
                    <?php if ($user_level <= 10): ?>
                        <a class="nav-link" href="#super">SuperAdmin Tools</a>
                    <?php endif; ?>
                </nav>
            </div>
        </div>

        <div class="col-md-9">

            <section id="quick-assist" class="mb-5">
                <div class="doc-card p-5">
                    <div class="role-badge bg-success text-white">Remote help</div>
                    <h2 class="font-weight-bold">Windows Quick Assist</h2>
                    <p>Click the button. Windows asks to open Quick Assist. Enter the 6-digit code we give you, then choose <strong>Allow</strong>.</p>
                    <p class="mb-0"><a class="btn btn-success font-weight-bold" href="ms-quick-assist://">Accept Quick Assist</a></p>
                </div>
            </section>

            <section id="overview" class="mb-5">
                <div class="doc-card p-5">
                    <div class="role-badge bg-light text-dark">Global Standards</div>
                    <h2 class="font-weight-bold">The SIP Philosophy</h2>
                    <p>Our platform is built on <strong>GPS Integrity</strong> and <strong>Visual Verification</strong>. Every action taken in the system is logged with a site-specific timestamp to ensure global payroll accuracy.</p>
                    
                    <div class="alert alert-info border-0" style="border-radius: 12px;">
                        <i class="fa fa-info-circle mr-2"></i> <strong>Note:</strong> All times displayed are adjusted to the site's local timezone automatically.
                    </div>
                </div>
            </section>

            <section id="page-guides" class="mb-5">
                <div class="doc-card p-5">
                    <div class="role-badge bg-success text-white">Text first</div>
                    <h2 class="font-weight-bold">Written tutorial of each page</h2>
                    <p>Every staff, operations, and inventory page has a written <strong>About this page</strong> guide. Click <strong>Video + audio</strong> for a spoken spotlight walkthrough of the real buttons (that is the video — it auto-plays, Pause anytime). Click <strong>Listen</strong> to hear the written guide. There is no separate MP4 file; the tour is generated from this text so it stays current.</p>
                    <div class="row mt-4">
                        <?php
                        $guides = function_exists('payroll_help_content') ? payroll_help_content() : [];
                        $shown = 0;
                        foreach ($guides as $pageKey => $guide) {
                            $href = '/' . ltrim((string)$pageKey, '/');
                            $title = htmlspecialchars((string)($guide['title'] ?? $pageKey));
                            $purpose = htmlspecialchars((string)($guide['purpose'] ?? ''));
                            if (strlen($purpose) > 180) {
                                $purpose = substr($purpose, 0, 177) . '...';
                            }
                            $shown++;
                            echo '<div class="col-md-6 mb-3">';
                            echo '<h6 class="font-weight-bold mb-1"><a href="' . htmlspecialchars($href) . '">' . $title . '</a></h6>';
                            echo '<small class="text-muted d-block">' . $purpose . '</small>';
                            echo '</div>';
                        }
                        if ($shown === 0) {
                            echo '<p class="text-muted mb-0">Open any page from the top menu — About this page is at the top of that screen.</p>';
                        }
                        ?>
                    </div>
                </div>
            </section>

            <section id="staff" class="mb-5">
                <div class="doc-card p-5">
                    <div class="icon-circle bg-primary text-white"><i class="fa fa-mobile-screen"></i></div>
                    <div class="role-badge bg-primary text-white">Staff Level</div>
                    <h2 class="font-weight-bold">Employee Daily Workflow</h2>
                    <div class="mt-4">
                        <div class="mb-3 d-flex align-items-center"><span class="step-num">1</span> <strong>Grant GPS Access:</strong> Your browser will ask for location. You must click "Allow" to clock in.</div>
                        <div class="mb-3 d-flex align-items-center"><span class="step-num">2</span> <strong>Selfie Identity:</strong> Ensure you are in a well-lit area. The system verifies your face against your profile.</div>
                        <div class="mb-3 d-flex align-items-center"><span class="step-num">3</span> <strong>The Geo-Fence:</strong> You must be within the site's radius (e.g., 200m) for the "IN" status to turn green.</div>
                    </div>
                </div>
            </section>

            <?php if ($user_level <= 30): ?>
            <section id="manager" class="mb-5">
                <div class="doc-card p-5 border-left" style="border-left: 5px solid #0ea5e9 !important;">
                    <div class="icon-circle bg-info text-white"><i class="fa fa-user-tie"></i></div>
                    <div class="role-badge bg-info text-white">Admin / Manager</div>
                    <h2 class="font-weight-bold">Approving Your Team</h2>
                    <p class="text-muted">Your primary tool is the <code>staff_audit.php</code> page.</p>
                    <div class="row mt-4">
                        <div class="col-md-6 mb-3">
                            <h6 class="font-weight-bold">Audit Monitoring</h6>
                            <small>Watch for <strong>Red GPS Icons</strong>. This means the employee was outside the site radius when they clocked in.</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <h6 class="font-weight-bold">Employee Hub</h6>
                            <small>Use <code>employee_hub.php</code> to toggle staff "Active" or "Off" instantly to control site access.</small>
                        </div>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($user_level <= 10): ?>
            <section id="super" class="mb-5">
                <div class="doc-card p-5 bg-dark text-white">
                    <div class="icon-circle bg-white text-dark"><i class="fa fa-crown"></i></div>
                    <div class="role-badge bg-warning text-dark">SuperAdmin Only</div>
                    <h2 class="font-weight-bold">Global Site Engineering</h2>
                    <p class="opacity-75">You control the operational mathematical models of the platform.</p>
                    <div class="mt-4 p-3 rounded" style="background: rgba(255,255,255,0.1);">
                        <ul class="list-unstyled small mb-0">
                            <li class="mb-2"><i class="fa fa-gear mr-2"></i> <strong>Site Growth:</strong> Adjust <code>site_growth_factor</code> to scale labor forecasts.</li>
                            <li class="mb-2"><i class="fa fa-location-dot mr-2"></i> <strong>Hard Geofence:</strong> Enter exact Lat/Long in <code>edit_site.php</code>.</li>
                            <li class="mb-2"><i class="fa fa-sitemap mr-2"></i> <strong>Org Fencing:</strong> Move sites between organizations as corporate structures change.</li>
                        </ul>
                    </div>
                </div>
            </section>
            <?php endif; ?>

        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Smooth scrolling for navigation pills
    $(document).ready(function(){
        $('.nav-link').on('click', function(event) {
            if (this.hash !== "") {
                event.preventDefault();
                var hash = this.hash;
                $('html, body').animate({
                    scrollTop: $(hash).offset().top - 120
                }, 800);
                $('.nav-link').removeClass('active');
                $(this).addClass('active');
            }
        });
    });
</script>
</body>
</html>