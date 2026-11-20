<?php
/**
 * CRA T4127 123rd edition, effective July 1, 2026.
 * Only the factors that changed are in this file. The January 1, 2026 pack still
 * supplies CPP, EI, federal tax, Ontario, and Saskatchewan.
 *
 * Source: Payroll Deductions Formulas, 123rd edition, effective July 1, 2026
 * (T4127(E) Rev. 26). British Columbia's lowest rate becomes 6.14% for Option 1
 * for the rest of 2026, because 5.06% was used for the first six months and the
 * full-year rate is 5.60%. The basic reduction used for the rest of the year is
 * $805 (the indexed January amount was $575; the announced full-year amount is $690).
 * The guide keeps the phase-out rate at 3.56% and cuts the credit off above $44,952.
 * Ontario, Saskatchewan, CPP, and EI are unchanged. Option 2 is not prorated; this
 * engine uses Option 1, which is the method for a regular pay period.
 */
return [
    'effective_from' => '2026-07-01',
    'edition' => 'T4127 123rd edition, effective July 1, 2026',
    'source_url' => 'https://www.canada.ca/en/revenue-agency/services/forms-publications/payroll/t4127-payroll-deductions-formulas/t4127-jul.html',
    'provinces' => [
        'BC' => [
            'name' => 'British Columbia',
            'bpa' => 13216.00,
            'lowest_rate' => 0.0614,
            'brackets' => [
                ['over' => 0, 'rate' => 0.0614, 'constant' => 0],
                ['over' => 50363, 'rate' => 0.077, 'constant' => 786],
                ['over' => 100728, 'rate' => 0.105, 'constant' => 3606],
                ['over' => 115648, 'rate' => 0.1229, 'constant' => 5676],
                ['over' => 140430, 'rate' => 0.147, 'constant' => 9061],
                ['over' => 190405, 'rate' => 0.168, 'constant' => 13059],
                ['over' => 265545, 'rate' => 0.205, 'constant' => 22884],
            ],
            'reduction' => [
                'type' => 'bc',
                'floor' => 25570.00,
                'basic' => 805.00,
                'rate' => 0.0356,
                'ceiling' => 44952.00,
            ],
        ],
    ],
];
