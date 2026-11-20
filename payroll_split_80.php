<?php
require_once __DIR__ . '/engine/orgscope_guard.php'; osg_cli_only(); // org-scope audit Nov 2026

/**
 * Path: /payroll_split_80.php
 * This page used to print SQL that wrote flat CPP, EI, and tax.
 * It does not run. Finalize hours on the payroll desk instead.
 */
header('Content-Type: text/plain; charset=UTF-8');
echo "disabled: use the engine\n";
echo "This screen is not a pay path. pe_line and the period refresh calculate CPP, EI, and tax.\n";
exit;
