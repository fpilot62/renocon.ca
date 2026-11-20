<?php
/** Login-only list of AA Group / Bramalea documents (Word + PDF). No markdown. */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../engine/org_modules_lib.php';

if (empty($_SESSION['user_id'])) {
    header('Location: /login_kiosk.php?err=session_expired');
    exit;
}

$aa = function_exists('sip_org_gdrive_folder') ? sip_org_gdrive_folder($pdo, 4) : ['url' => '', 'label' => ''];
$gdrive_url = $aa['url'] !== '' ? $aa['url'] : 'https://drive.google.com/drive/folders/1W9PDGq-uUAzz1RRF7b2dX5';
$gdrive_label = $aa['label'] !== '' ? $aa['label'] : '1.Software / aa group';

$files = [
    'list_pdf'  => ['AA_Group_document_list.pdf', 'application/pdf'],
    'list_docx' => ['AA_Group_document_list.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    'gaps_pdf'  => ['Accountant_gaps_Bramalea.pdf', 'application/pdf'],
    'gaps_docx' => ['Accountant_gaps_Bramalea.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    'pdf'       => ['Bramalea_Aug10-23_compare.pdf', 'application/pdf'],
    'docx'      => ['Bramalea_Aug10-23_compare.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
];

$want = strtolower(trim((string)($_GET['file'] ?? '')));
if (isset($files[$want])) {
    [$name, $mime] = $files[$want];
    $path = __DIR__ . '/' . $name;
    if (!is_file($path)) {
        http_response_code(404);
        echo 'File not found.';
        exit;
    }
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>AA Group documents</title>
    <style>
        body { font-family: Segoe UI, sans-serif; background:#f1f5f9; margin:0; padding:32px; color:#0f172a; }
        .card { max-width:720px; margin:24px auto; background:#fff; border-radius:12px; padding:28px; box-shadow:0 8px 24px rgba(15,23,42,.08); }
        h1 { font-size:22px; margin:0 0 6px; }
        p { color:#475569; line-height:1.5; }
        table { width:100%; border-collapse:collapse; margin:16px 0; font-size:14px; }
        th { text-align:left; background:#0f172a; color:#fff; padding:8px 10px; }
        td { border-bottom:1px solid #e2e8f0; padding:10px; vertical-align:top; }
        .btn { display:inline-block; margin:4px 6px 0 0; padding:8px 12px; border-radius:8px; font-weight:700; text-decoration:none; font-size:13px; }
        .pdf { background:#0f172a; color:#fff; }
        .doc { background:#1d4ed8; color:#fff; }
        .drive { background:#188038; color:#fff; }
        .note { font-size:13px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:12px; }
        .steps { background:#fff7ed; border:1px solid #fed7aa; border-radius:8px; padding:12px 12px 4px; font-size:14px; color:#9a3412; }
        .steps li { margin:0 0 8px; }
    </style>
</head>
<body>
    <div class="card">
        <h1>AA Group — document list</h1>
        <p>Word and PDF only. No markdown on Google. Open the Word in Drive if you want a Google Doc.</p>
        <p class="note">Folder: <strong><?= htmlspecialchars($gdrive_label) ?></strong><br>
            <a class="btn drive" href="<?= htmlspecialchars($gdrive_url) ?>" target="_blank" rel="noopener">Open AA Group Drive folder</a>
        </p>
        <table>
            <tr><th>#</th><th>Document</th><th>Download</th></tr>
            <tr>
                <td>1</td>
                <td><strong>This list</strong><br>Index of the files for the accountant.</td>
                <td>
                    <a class="btn doc" href="?file=list_docx">Word</a>
                    <a class="btn pdf" href="?file=list_pdf">PDF</a>
                </td>
            </tr>
            <tr>
                <td>2</td>
                <td><strong>Bramalea payroll compare — Aug 10–23</strong><br>Regular, vac, CPP, EI, tax vs their QuickBooks.</td>
                <td>
                    <a class="btn doc" href="?file=docx">Word</a>
                    <a class="btn pdf" href="?file=pdf">PDF</a>
                </td>
            </tr>
            <tr>
                <td>3</td>
                <td><strong>Where accountants are short</strong><br>CRA / T4 / ROE / remittance gaps. Nobody was paid.</td>
                <td>
                    <a class="btn doc" href="?file=gaps_docx">Word</a>
                    <a class="btn pdf" href="?file=gaps_pdf">PDF</a>
                </td>
            </tr>
        </table>
        <ol class="steps">
            <li>Open the green <strong>Drive folder</strong>.</li>
            <li><strong>New → File upload</strong> — pick the Word (not any .md).</li>
            <li>Right-click the Word → <strong>Open with → Google Docs</strong>.</li>
            <li>It becomes a Google Doc in that folder. Repeat for the other Word files.</li>
        </ol>
    </div>
</body>
</html>
