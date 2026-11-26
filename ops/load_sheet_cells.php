<?php
/**
 * Load the source sheet cells for the Sheet Review side panel.
 *
 *   php ops/load_sheet_cells.php dry   <org_id> <cells.csv>
 *   php ops/load_sheet_cells.php apply <org_id> <cells.csv>
 *
 * cells.csv columns: sheet,row,col,value,formula,is_number
 * It is made from the workbook on the box and is not kept in the repository,
 * because it holds employee names and amounts.
 *
 * dry writes nothing. It matches every stored draft row of each sheet with the
 * cells and stops on any mismatch. apply replaces the stored cells for those
 * sheets and imports in one transaction per import. It touches only
 * payroll_sheet_cells. It does not change a draft row, approve, or pay.
 */
$app = getenv('PAYROLL_APP') ?: '/home/renocon/public_html/payroll';
require $app . '/db.php';
require $app . '/engine/payroll_sheet_edit_lib.php';
if (!isset($pdo) || !$pdo instanceof PDO) {
    fwrite(STDERR, "db.php did not provide a connection\n");
    exit(1);
}
$mode = $argv[1] ?? '';
$orgId = (int)($argv[2] ?? 0);
$csv = $argv[3] ?? '';
if (!in_array($mode, ['dry', 'apply'], true) || $orgId <= 0 || !is_file($csv)) {
    fwrite(STDERR, "Usage: php load_sheet_cells.php dry|apply <org_id> <cells.csv>\n");
    exit(1);
}
try {
    $cells = pse_cells_parse_csv((string)file_get_contents($csv));
    if (!$cells) {
        throw new RuntimeException('The cells file has no cells.');
    }
    $sheets = [];
    foreach ($cells as $c) {
        $sheets[$c['sheet']][] = $c;
    }
    $tablesReady = true;
    try {
        $pdo->query('SELECT 1 FROM payroll_sheet_cells LIMIT 1');
    } catch (Throwable $e) {
        $tablesReady = false;
    }
    if ($mode === 'dry') {
        echo $tablesReady ? "payroll_sheet_cells exists.\n" : "payroll_sheet_cells does not exist yet. Deploy the code first (deploy_sheet_edit_v1.sh go).\n";
    }
    $plan = [];
    foreach ($sheets as $sheet => $sheetCells) {
        $st = $pdo->prepare('SELECT * FROM payroll_sheet_rows WHERE org_id = ? AND source_sheet = ? AND deleted_at IS NULL');
        $st->execute([$orgId, $sheet]);
        $drafts = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$drafts) {
            throw new RuntimeException("No draft rows for sheet '$sheet' in org $orgId. Nothing to attach the cells to.");
        }
        $byImport = [];
        foreach ($drafts as $d) {
            $byImport[(int)$d['import_id']][] = $d;
        }
        foreach ($byImport as $importId => $rows) {
            $v = pse_cells_verify($sheetCells, $rows);
            echo "sheet '$sheet' import $importId: " . count($rows) . " draft rows, " . count($sheetCells) . " cells, {$v['checked']} raw values compared, "
                . count($v['mismatches']) . ' mismatches, ' . count($v['missing_rows']) . " draft rows without cells\n";
            foreach (array_slice($v['mismatches'], 0, 10) as $m) {
                echo "  MISMATCH $m\n";
            }
            if ($v['mismatches'] || $v['missing_rows']) {
                throw new RuntimeException('The cells do not match the stored drafts for import ' . $importId . '. Nothing was written.');
            }
            $plan[] = [$importId, $sheetCells];
        }
    }
    if ($mode === 'dry') {
        echo "Dry run wrote nothing.\n";
        exit(0);
    }
    $total = 0;
    foreach ($plan as [$importId, $sheetCells]) {
        $total += pse_cells_store($pdo, $orgId, $importId, $sheetCells);
    }
    echo "Stored $total cells. Draft rows, approvals and payments were not touched.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Load stopped: ' . $e->getMessage() . "\n");
    exit(1);
}
