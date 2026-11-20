<?php
/**
 * CRA T4127 122nd edition, effective January 1, 2026.
 * Numbers are data. The calculator does not repeat them.
 *
 * Sources (fetched for this engine, November 2026):
 * - T4127(E) 122nd edition, effective January 1, 2026, Table 8.1 and the Option 1 formulas
 *   https://www.canada.ca/content/dam/cra-arc/formspubs/pub/t4127-jan/t4127-01-26e.pdf
 * - Table 8.1 CSV rtsncmtrshldcnstnt-01-26e.csv (federal, BC, ON, SK brackets and constants)
 * - Table 8.3 CPP/QPP cpp-qpp-ttl-01-26e.csv (YMPE 74,600, basic exemption 3,500, rate 5.95%, max 4,230.45)
 * - EI ei-01-26e.csv (max insurable 68,900, employee 1.63%, max premium 1,123.07, employer 1.4 times)
 * - Table 8.2 other amounts: federal BPAF max 16,452 / min 14,829, CEA 1,501,
 *   BC basic 13,216 and S2 575, Ontario basic 12,989, surtax 5,818 / 7,446, S2 300,
 *   Saskatchewan basic 20,381
 * - BPAF formula in chapter 2 of the same guide
 * - Chapter 6 basic-exemption table (amounts are the published truncated cents, not rounded)
 * - Ontario Health Premium bands are the T4127 factor V2 (the payroll formula, which lands on the same steps as ON428)
 * - Ontario EHT: Employer Health Tax Act, R.S.O. 1990, c. E.11, and ontario.ca/document/employer-health-tax-eht
 *   Exemption $1,000,000 through 2028. Not indexed until January 1, 2029.
 * - WSIB 2026 maximum insurable earnings $121,700 (wsib.ca/en/2026premiumrates)
 * - WorkSafeBC 2026 maximum wage rate $127,500, used here as the assessable-earnings cap
 *   (worksafebc.com, June 2, 2025 approval, effective January 1, 2026)
 * - Saskatchewan WCB 2026 maximum assessable earnings $108,223 (wcbsask.com/maximum-assessable-earnings)
 *
 * Employer WSIB/WCB rates are NOT in this file. Each site stores its own percentage.
 */
return [
    'effective_from' => '2026-01-01',
    'edition' => 'T4127 122nd edition, effective January 1, 2026',
    'source_url' => 'https://www.canada.ca/en/revenue-agency/services/forms-publications/payroll/t4127-payroll-deductions-formulas/t4127-jan.html',
    'cpp' => [
        'ympe' => 74600.00,
        'yampe' => 85000.00,
        'basic_exemption' => 3500.00,
        'total_rate' => 0.0595,
        'base_rate' => 0.0495,
        'first_additional_rate' => 0.0100,
        'second_rate' => 0.04,
        'max_total' => 4230.45,
        'max_base' => 3519.45,
        'max_first_additional' => 711.00,
        'max_second' => 416.00,
        // Chapter 6 table. Truncated to the cent, not rounded.
        'exemption_by_periods' => [
            1 => 3500.00,
            2 => 1750.00,
            4 => 875.00,
            10 => 350.00,
            12 => 291.66,
            13 => 269.23,
            22 => 159.09,
            24 => 145.83,
            26 => 134.61,
            27 => 129.62,
            52 => 67.30,
            53 => 66.03,
            240 => 14.58,
        ],
    ],
    'ei' => [
        'max_insurable' => 68900.00,
        'employee_rate' => 0.0163,
        'employer_multiple' => 1.4,
        'max_employee' => 1123.07,
        'max_employer' => 1572.30,
        'quebec_employee_rate' => 0.0130,
        'quebec_max_employee' => 895.70,
    ],
    'federal' => [
        'lowest_rate' => 0.14,
        'brackets' => [
            ['over' => 0, 'rate' => 0.14, 'constant' => 0],
            ['over' => 58523, 'rate' => 0.205, 'constant' => 3804],
            ['over' => 117045, 'rate' => 0.26, 'constant' => 10241],
            ['over' => 181440, 'rate' => 0.29, 'constant' => 15685],
            ['over' => 258482, 'rate' => 0.33, 'constant' => 26024],
        ],
        'bpa_max' => 16452.00,
        'bpa_min' => 14829.00,
        'bpa_phase_start' => 181440.00,
        'bpa_phase_end' => 258482.00,
        'cea' => 1501.00,
        'bonus_flat_threshold' => 5000.00,
        'bonus_flat_rate' => 0.15,
    ],
    'provinces' => [
        'BC' => [
            'name' => 'British Columbia',
            'bpa' => 13216.00,
            'lowest_rate' => 0.0506,
            'brackets' => [
                ['over' => 0, 'rate' => 0.0506, 'constant' => 0],
                ['over' => 50363, 'rate' => 0.077, 'constant' => 1330],
                ['over' => 100728, 'rate' => 0.105, 'constant' => 4150],
                ['over' => 115648, 'rate' => 0.1229, 'constant' => 6220],
                ['over' => 140430, 'rate' => 0.147, 'constant' => 9604],
                ['over' => 190405, 'rate' => 0.168, 'constant' => 13603],
                ['over' => 265545, 'rate' => 0.205, 'constant' => 23428],
            ],
            'reduction' => [
                'type' => 'bc',
                'floor' => 25570.00,
                'basic' => 575.00,
                'rate' => 0.0356,
                'ceiling' => 41722.00,
            ],
        ],
        'ON' => [
            'name' => 'Ontario',
            'bpa' => 12989.00,
            'lowest_rate' => 0.0505,
            'brackets' => [
                ['over' => 0, 'rate' => 0.0505, 'constant' => 0],
                ['over' => 53891, 'rate' => 0.0915, 'constant' => 2210],
                ['over' => 107785, 'rate' => 0.1116, 'constant' => 4376],
                ['over' => 150000, 'rate' => 0.1216, 'constant' => 5876],
                ['over' => 220000, 'rate' => 0.1316, 'constant' => 8076],
            ],
            'surtax' => [
                ['over' => 5818.00, 'rate' => 0.20],
                ['over' => 7446.00, 'rate' => 0.36],
            ],
            'reduction' => [
                'type' => 'on',
                'basic' => 300.00,
                'per_dependant' => 554.00,
            ],
            // T4127 factor V2. The "lesser of" steps reproduce the Ontario Health Premium plateaus.
            'health_premium' => [
                ['up_to' => 20000.00, 'base' => 0, 'rate' => 0, 'over' => 0, 'cap' => 0],
                ['up_to' => 36000.00, 'base' => 0, 'rate' => 0.06, 'over' => 20000.00, 'cap' => 300],
                ['up_to' => 48000.00, 'base' => 300, 'rate' => 0.06, 'over' => 36000.00, 'cap' => 450],
                ['up_to' => 72000.00, 'base' => 450, 'rate' => 0.25, 'over' => 48000.00, 'cap' => 600],
                ['up_to' => 200000.00, 'base' => 600, 'rate' => 0.25, 'over' => 72000.00, 'cap' => 750],
                ['up_to' => null, 'base' => 750, 'rate' => 0.25, 'over' => 200000.00, 'cap' => 900],
            ],
        ],
        'SK' => [
            'name' => 'Saskatchewan',
            'bpa' => 20381.00,
            'lowest_rate' => 0.105,
            'brackets' => [
                ['over' => 0, 'rate' => 0.105, 'constant' => 0],
                ['over' => 54532, 'rate' => 0.125, 'constant' => 1091],
                ['over' => 155805, 'rate' => 0.145, 'constant' => 4207],
            ],
        ],
    ],
    'eht_on' => [
        'exemption' => 1000000.00,
        'group_payroll_cap' => 5000000.00,
        'tiers' => [
            ['up_to' => 200000.00, 'rate' => 0.0098],
            ['up_to' => 230000.00, 'rate' => 0.01101],
            ['up_to' => 260000.00, 'rate' => 0.01223],
            ['up_to' => 290000.00, 'rate' => 0.01344],
            ['up_to' => 320000.00, 'rate' => 0.01465],
            ['up_to' => 350000.00, 'rate' => 0.01586],
            ['up_to' => 380000.00, 'rate' => 0.01708],
            ['up_to' => 400000.00, 'rate' => 0.01829],
            ['up_to' => null, 'rate' => 0.0195],
        ],
    ],
    'assessable_max' => [
        'ON' => 121700.00,
        'BC' => 127500.00,
        'SK' => 108223.00,
    ],
];
