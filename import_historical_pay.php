<?php
/**
 * Path: /import_historical_pay.php   (run in browser, then DELETE)
 *
 * Final historical back-pay import. Positive amounts = gross owed -> payroll engine
 * computes deductions -> inserted into payroll_finalized (unpaid). Negative amounts =
 * cash already paid -> applied as paid_amount against that person's imported balance.
 *
 *   ?mode=dry            -> preview only, writes NOTHING (default)
 *   ?mode=live           -> insert for real
 *   ?site=Penticton      -> limit to one site (test). Omit = all sites.
 *
 * Requires db.php and payroll_engine/payroll_engine.php.
 */
ini_set('display_errors',1); error_reporting(E_ALL);
if (session_status()===PHP_SESSION_NONE) session_start();
require_once 'db.php';
require_once __DIR__.'/payroll_engine/payroll_engine.php';

$MODE = ($_GET['mode'] ?? 'dry')==='live' ? 'live':'dry';
$ONLY = $_GET['site'] ?? '';

$MAP = [
  'Vina|Penticton'=>10152,
  'Rowena|Penticton'=>10160,
  'Ronel|Penticton'=>10173,
  'Rishi|Westbank'=>10094,
  'Ayesha|Westbank'=>10093,
  'Ranveer|Westbank'=>10044,
  'Anpinder|Westbank'=>10051,
  'Shobha|Westbank'=>10169,
  'Berna|Westbank'=>10100,
  'Arash Bleem|Vernon'=>10037,
  'Navpreet|Vernon'=>10052,
  'Deepak|Vernon'=>10012,
  'Naseem|Vernon'=>10109,
  'Rajbir|Vernon'=>10108,
  'Megha|Merritt'=>10053,
  'Gurpreet Kaur|Merritt'=>10158,
  'Rajdeep|Merritt'=>10142,
  'Arshdeep Singh|Merritt'=>10116,
  'Rania|Salmon Arm'=>10113,
  'Habib|Salmon Arm'=>10112,
  'Anju|Salmon Arm'=>10054,
  'Tanvir|Harvey'=>10132,
  'Arshpreet Kaur|Harvey'=>10017,
  'Tabeer|Harvey'=>10007,
  'John Paul|Harvey'=>10102,
  'Aaditya|Harvey'=>10180,
  'Arshdeep Kaur|Harvey'=>10157,
  'Simranjot Kaur|Harvey'=>10095,
  'Ruby|Harvey'=>10098,
  'Gurprabh|Harvey'=>10156,
  'Komal|Harvey'=>10161,
  'Ramandeep K|Harvey'=>10179,
];
$CREATE = [
  'Kamalpreet Singh'=>6,
  'Rohit'=>6,
  'Anjali'=>6,
  'Kamalpreet Kaur'=>6,
  'Connor'=>6,
  'Amrit'=>6,
  'Kiran'=>19,
  'Anuket'=>20,
  'Yuvraj'=>20,
];      // name => site_id (looked up by name after creation)
$SITE_ID = ['Penticton'=>6,'Westbank'=>11,'Vernon'=>19,'Merritt'=>20,'Salmon Arm'=>21,'Harvey'=>23];
$LINES = [
  ['site'=>'Penticton','site_id'=>6,'emp'=>'Kamalpreet Singh','period'=>'Sep 1-15','amount'=>716.0],
  ['site'=>'Penticton','site_id'=>6,'emp'=>'Kamalpreet Singh','period'=>'Nov 1-15','amount'=>416.0],
  ['site'=>'Penticton','site_id'=>6,'emp'=>'Kamalpreet Singh','period'=>'Nov 16-31','amount'=>315.0],
  ['site'=>'Penticton','site_id'=>6,'emp'=>'Rohit','period'=>'Sep 1-15','amount'=>1897.0],
  ['site'=>'Penticton','site_id'=>6,'emp'=>'Rohit','period'=>'Nov 16-31','amount'=>2376.0],
  ['site'=>'Penticton','site_id'=>6,'emp'=>'Rohit','period'=>'Dec 1-15','amount'=>-1000.0],
  ['site'=>'Penticton','site_id'=>6,'emp'=>'Anjali','period'=>'Sep 1-15','amount'=>1556.0],
  ['site'=>'Penticton','site_id'=>6,'emp'=>'Anjali','period'=>'Nov 1-15','amount'=>626.0],
  ['site'=>'Penticton','site_id'=>6,'emp'=>'Anjali','period'=>'Nov 16-31','amount'=>614.0],
  ['site'=>'Penticton','site_id'=>6,'emp'=>'Kamalpreet Kaur','period'=>'Nov 1-15','amount'=>1328.58],
  ['site'=>'Penticton','site_id'=>6,'emp'=>'Connor','period'=>'Nov 1-15','amount'=>1577.79],
  ['site'=>'Penticton','site_id'=>6,'emp'=>'Connor','period'=>'Jan 1-15','amount'=>556.74],
  ['site'=>'Penticton','site_id'=>6,'emp'=>'Amrit','period'=>'Nov 1-15','amount'=>1660.0],
  ['site'=>'Penticton','site_id'=>6,'emp'=>'Vina','period'=>'June 16-31','amount'=>2459.36],
  ['site'=>'Penticton','site_id'=>6,'emp'=>'Rowena','period'=>'June 1-15','amount'=>2349.42],
  ['site'=>'Penticton','site_id'=>6,'emp'=>'Rowena','period'=>'June 16-31','amount'=>2235.8],
  ['site'=>'Penticton','site_id'=>6,'emp'=>'Ronel','period'=>'June 16-31','amount'=>1828.65],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Rishi','period'=>'Jan 1-15','amount'=>1926.31],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Rishi','period'=>'Jan 16-31','amount'=>1963.32],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Rishi','period'=>'Feb 1-15','amount'=>2153.72],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Rishi','period'=>'Feb 16-28','amount'=>2467.0],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Rishi','period'=>'March 1-15','amount'=>3002.73],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Rishi','period'=>'April 1-15','amount'=>2754.97],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Rishi','period'=>'May 1-15','amount'=>2906.0],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Rishi','period'=>'May 16-31','amount'=>3148.0],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Rishi','period'=>'June 1-15','amount'=>3166.9],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Rishi','period'=>'June 16-30','amount'=>2795.3],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Ayesha','period'=>'Dec 1-15','amount'=>1041.96],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Ayesha','period'=>'Jan 1-15','amount'=>981.16],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Ayesha','period'=>'Jan 16-31','amount'=>788.5],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Ayesha','period'=>'Feb 16-28','amount'=>935.75],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Ayesha','period'=>'Mar 1-15','amount'=>1613.29],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Ayesha','period'=>'April 1-15','amount'=>1565.98],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Ayesha','period'=>'May 1-15','amount'=>501.98],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Ayesha','period'=>'Jun 1-15','amount'=>376.77],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Ayesha','period'=>'June 16-31','amount'=>1485.23],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Ranveer','period'=>'Dec 16-31','amount'=>1712.0],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Ranveer','period'=>'Jan 1-15','amount'=>2637.36],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Ranveer','period'=>'Jan 16-31','amount'=>2280.87],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Ranveer','period'=>'Feb 1-15','amount'=>1958.78],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Ranveer','period'=>'Feb 16-28','amount'=>1197.88],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Ranveer','period'=>'Mar 1-15','amount'=>932.59],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Ranveer','period'=>'30-Jun','amount'=>-1500.0],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Anpinder','period'=>'Mar 1-15','amount'=>1885.34],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Anpinder','period'=>'Mar 16-31','amount'=>2226.11],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Anpinder','period'=>'April 1-15','amount'=>1572.5],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Anpinder','period'=>'June 16-31','amount'=>1408.96],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Shobha','period'=>'Mar 1-15','amount'=>1580.76],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Shobha','period'=>'May 1-15','amount'=>2373.4],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Shobha','period'=>'June 1-15','amount'=>1879.0],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Shobha','period'=>'June 16-31','amount'=>2323.95],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Berna','period'=>'Aug 16-31','amount'=>1702.0],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Berna','period'=>'Oct 1-15','amount'=>1453.0],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Berna','period'=>'Nov 16-31','amount'=>1454.0],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Berna','period'=>'Dec 1-15','amount'=>1118.0],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Berna','period'=>'Dec 16-31','amount'=>1659.0],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Berna','period'=>'Jan 1-15','amount'=>914.49],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Berna','period'=>'Feb 16-28','amount'=>1091.88],
  ['site'=>'Westbank','site_id'=>11,'emp'=>'Berna','period'=>'Mar 1-15','amount'=>796.86],
  ['site'=>'Vernon','site_id'=>19,'emp'=>'Arash Bleem','period'=>'Sep 1-15','amount'=>1731.0],
  ['site'=>'Vernon','site_id'=>19,'emp'=>'Navpreet','period'=>'Mar 1-15','amount'=>1874.73],
  ['site'=>'Vernon','site_id'=>19,'emp'=>'Navpreet','period'=>'May 16-31','amount'=>2307.93],
  ['site'=>'Vernon','site_id'=>19,'emp'=>'Navpreet','period'=>'June 1-15','amount'=>1995.76],
  ['site'=>'Vernon','site_id'=>19,'emp'=>'Navpreet','period'=>'June 16-31','amount'=>1296.75],
  ['site'=>'Vernon','site_id'=>19,'emp'=>'Deepak','period'=>'Mar 1-15','amount'=>2007.28],
  ['site'=>'Vernon','site_id'=>19,'emp'=>'Deepak','period'=>'May 1-15','amount'=>2442.0],
  ['site'=>'Vernon','site_id'=>19,'emp'=>'Deepak','period'=>'June 1-15','amount'=>2757.26],
  ['site'=>'Vernon','site_id'=>19,'emp'=>'Deepak','period'=>'June 16-31','amount'=>1600.0],
  ['site'=>'Vernon','site_id'=>19,'emp'=>'Naseem','period'=>'Mar 1-15','amount'=>1790.64],
  ['site'=>'Vernon','site_id'=>19,'emp'=>'Naseem','period'=>'May 1-15','amount'=>1327.68],
  ['site'=>'Vernon','site_id'=>19,'emp'=>'Naseem','period'=>'June 1-15','amount'=>1762.58],
  ['site'=>'Vernon','site_id'=>19,'emp'=>'Naseem','period'=>'June 16-31','amount'=>2316.47],
  ['site'=>'Vernon','site_id'=>19,'emp'=>'Rajbir','period'=>'May 1-15','amount'=>1264.5],
  ['site'=>'Vernon','site_id'=>19,'emp'=>'Rajbir','period'=>'May 16-31','amount'=>1742.04],
  ['site'=>'Vernon','site_id'=>19,'emp'=>'Kiran','period'=>'Sept 1-15','amount'=>2090.0],
  ['site'=>'Merritt','site_id'=>20,'emp'=>'Arshdeep Singh','period'=>'May 16-31','amount'=>1332.19],
  ['site'=>'Merritt','site_id'=>20,'emp'=>'Arshdeep Singh','period'=>'June 1-15','amount'=>1193.44],
  ['site'=>'Merritt','site_id'=>20,'emp'=>'Arshdeep Singh','period'=>'June 16-30','amount'=>2172.09],
  ['site'=>'Merritt','site_id'=>20,'emp'=>'Megha','period'=>'Feb 1-15','amount'=>2173.6],
  ['site'=>'Merritt','site_id'=>20,'emp'=>'Megha','period'=>'Feb 16-28','amount'=>1964.41],
  ['site'=>'Merritt','site_id'=>20,'emp'=>'Megha','period'=>'Mar 1-15','amount'=>1088.75],
  ['site'=>'Merritt','site_id'=>20,'emp'=>'Megha','period'=>'Mar 16-31','amount'=>1060.58],
  ['site'=>'Merritt','site_id'=>20,'emp'=>'Megha','period'=>'April 1-15','amount'=>1448.75],
  ['site'=>'Merritt','site_id'=>20,'emp'=>'Megha','period'=>'April 16-30','amount'=>1448.94],
  ['site'=>'Merritt','site_id'=>20,'emp'=>'Megha','period'=>'May 16-31','amount'=>1678.46],
  ['site'=>'Merritt','site_id'=>20,'emp'=>'Megha','period'=>'June 1-15','amount'=>1425.19],
  ['site'=>'Merritt','site_id'=>20,'emp'=>'Megha','period'=>'June 16-30','amount'=>1616.52],
  ['site'=>'Merritt','site_id'=>20,'emp'=>'Anuket','period'=>'Jan 1-15','amount'=>847.86],
  ['site'=>'Merritt','site_id'=>20,'emp'=>'Anuket','period'=>'Jan 16-30','amount'=>956.08],
  ['site'=>'Merritt','site_id'=>20,'emp'=>'Anuket','period'=>'Feb 1-15','amount'=>1065.23],
  ['site'=>'Merritt','site_id'=>20,'emp'=>'Anuket','period'=>'Feb 16-28','amount'=>868.02],
  ['site'=>'Merritt','site_id'=>20,'emp'=>'Anuket','period'=>'Paid on June 16','amount'=>-3736.0],
  ['site'=>'Merritt','site_id'=>20,'emp'=>'Gurpreet Kaur','period'=>'June 1-15','amount'=>1176.44],
  ['site'=>'Merritt','site_id'=>20,'emp'=>'Gurpreet Kaur','period'=>'June 16-30','amount'=>1501.7],
  ['site'=>'Merritt','site_id'=>20,'emp'=>'Rajdeep','period'=>'Sept 1-15','amount'=>945.0],
  ['site'=>'Merritt','site_id'=>20,'emp'=>'Rajdeep','period'=>'Nov 16-30','amount'=>1713.0],
  ['site'=>'Merritt','site_id'=>20,'emp'=>'Rajdeep','period'=>'Jan 1-15','amount'=>1602.39],
  ['site'=>'Merritt','site_id'=>20,'emp'=>'Rajdeep','period'=>'Jan 16-30','amount'=>1466.56],
  ['site'=>'Merritt','site_id'=>20,'emp'=>'Rajdeep','period'=>'Feb 16-28','amount'=>1164.89],
  ['site'=>'Merritt','site_id'=>20,'emp'=>'Rajdeep','period'=>'Mar 1-15','amount'=>71.4],
  ['site'=>'Merritt','site_id'=>20,'emp'=>'Rajdeep','period'=>'Paid on June 16','amount'=>-1713.0],
  ['site'=>'Merritt','site_id'=>20,'emp'=>'Yuvraj','period'=>'Nov 16-31','amount'=>962.47],
  ['site'=>'Merritt','site_id'=>20,'emp'=>'Yuvraj','period'=>'Jan 1-15','amount'=>172.61],
  ['site'=>'Salmon Arm','site_id'=>21,'emp'=>'Rania','period'=>'Jan 1-15','amount'=>1100.81],
  ['site'=>'Salmon Arm','site_id'=>21,'emp'=>'Rania','period'=>'Feb 1-15','amount'=>1033.52],
  ['site'=>'Salmon Arm','site_id'=>21,'emp'=>'Rania','period'=>'Feb 16-28','amount'=>748.09],
  ['site'=>'Salmon Arm','site_id'=>21,'emp'=>'Rania','period'=>'Mar 1-15','amount'=>612.61],
  ['site'=>'Salmon Arm','site_id'=>21,'emp'=>'Anju','period'=>'May 1-15','amount'=>1808.61],
  ['site'=>'Salmon Arm','site_id'=>21,'emp'=>'Anju','period'=>'May 16-30','amount'=>1875.54],
  ['site'=>'Salmon Arm','site_id'=>21,'emp'=>'Anju','period'=>'June 1-15','amount'=>774.25],
  ['site'=>'Salmon Arm','site_id'=>21,'emp'=>'Anju','period'=>'Mar 16-30','amount'=>1683.5],
  ['site'=>'Salmon Arm','site_id'=>21,'emp'=>'Anju','period'=>'April 16-30','amount'=>1683.5],
  ['site'=>'Salmon Arm','site_id'=>21,'emp'=>'Habib','period'=>'Feb 16-28','amount'=>1480.12],
  ['site'=>'Salmon Arm','site_id'=>21,'emp'=>'Habib','period'=>'Mar 1-15','amount'=>462.67],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Tanvir','period'=>'Dec 1-15','amount'=>328.38],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Tanvir','period'=>'Jan 1-15','amount'=>1029.16],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Tanvir','period'=>'Jan 16-28','amount'=>1645.21],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Tanvir','period'=>'Feb 1-15','amount'=>1470.75],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Tanvir','period'=>'Feb 16-28','amount'=>1699.23],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Tanvir','period'=>'Mar 1-15','amount'=>1026.75],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Tanvir','period'=>'Mar 16-31','amount'=>869.5],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Tanvir','period'=>'April 1-15','amount'=>1170.13],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Tanvir','period'=>'Sep 1-15','amount'=>1388.98],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Arshpreet Kaur','period'=>'Oct 16-31','amount'=>3488.0],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Arshpreet Kaur','period'=>'Nov 1-15','amount'=>3500.0],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Arshpreet Kaur','period'=>'Nov 16-30','amount'=>3500.0],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Arshpreet Kaur','period'=>'Dec 1-15','amount'=>2602.86],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Arshpreet Kaur','period'=>'1-Apr','amount'=>2753.99],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Arshpreet Kaur','period'=>'2-Apr','amount'=>2376.08],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Arshpreet Kaur','period'=>'1-May','amount'=>2712.06],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Arshpreet Kaur','period'=>'2-May','amount'=>2629.77],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Arshpreet Kaur','period'=>'1-Jun','amount'=>2835.5],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Arshpreet Kaur','period'=>'2-Jun','amount'=>2708.94],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Tabeer','period'=>'2-Jun','amount'=>1000.0],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'John Paul','period'=>'Dec 1-15','amount'=>2055.69],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'John Paul','period'=>'Mar 1-15','amount'=>2070.12],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'John Paul','period'=>'1-May','amount'=>2228.85],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'John Paul','period'=>'2-May','amount'=>2599.55],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'John Paul','period'=>'1-Jun','amount'=>2032.88],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'John Paul','period'=>'2-Jun','amount'=>2419.76],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Aaditya','period'=>'Dec 1-15','amount'=>1540.89],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Aaditya','period'=>'jan 1-15','amount'=>1698.06],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Aaditya','period'=>'Feb 16-28','amount'=>1118.13],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Arshdeep Kaur','period'=>'Aug 16-31','amount'=>2756.0],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Arshdeep Kaur','period'=>'Sept 1-15','amount'=>1314.0],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Arshdeep Kaur','period'=>'Oct 16-31','amount'=>1105.0],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Arshdeep Kaur','period'=>'Nov 1-15','amount'=>1220.0],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Arshdeep Kaur','period'=>'Jan 1-15','amount'=>1245.78],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Arshdeep Kaur','period'=>'Jan 16-31','amount'=>1618.56],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Arshdeep Kaur','period'=>'Feb 1-15','amount'=>1429.38],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Arshdeep Kaur','period'=>'Feb 16-28','amount'=>1237.32],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Arshdeep Kaur','period'=>'Mar 1-15','amount'=>737.82],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Simranjot Kaur','period'=>'Oct 1-15','amount'=>941.84],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Simranjot Kaur','period'=>'Oct 16-31','amount'=>2840.0],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Simranjot Kaur','period'=>'Nov 1-15','amount'=>3300.0],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Simranjot Kaur','period'=>'Nov 16-31','amount'=>2864.0],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Simranjot Kaur','period'=>'Dec 1-15','amount'=>3029.13],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Simranjot Kaur','period'=>'Jan 16-31','amount'=>3463.98],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Simranjot Kaur','period'=>'Feb 16-28','amount'=>2550.99],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Simranjot Kaur','period'=>'Mar 1-15','amount'=>2699.19],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Simranjot Kaur','period'=>'7-May','amount'=>-487.0],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Ruby','period'=>'Nov 1-15','amount'=>2000.0],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Ruby','period'=>'Nov 16-30','amount'=>2000.0],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Ruby','period'=>'Dec 1-15','amount'=>2441.0],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Ruby','period'=>'Dec 16-31','amount'=>2441.0],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Ruby','period'=>'Jan 1-15','amount'=>1492.38],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Ruby','period'=>'Jan 16-31','amount'=>2527.2],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Ruby','period'=>'Feb 1-15','amount'=>2392.56],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Ruby','period'=>'Feb 16-28','amount'=>1885.75],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Gurprabh','period'=>'Dec 1-15','amount'=>1432.27],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Gurprabh','period'=>'Jan 1-15','amount'=>908.17],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Gurprabh','period'=>'Jan 16-31','amount'=>1836.31],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Gurprabh','period'=>'Feb 16-28','amount'=>1812.28],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Gurprabh','period'=>'Mar 1-15','amount'=>479.52],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Gurprabh','period'=>'Aug 16-31','amount'=>2176.89],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Gurprabh','period'=>'Sep 1-15','amount'=>1578.61],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Komal','period'=>'Jan 1-15','amount'=>350.0],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Komal','period'=>'Jan 16-31','amount'=>635.0],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Ramandeep K','period'=>'June 1-15','amount'=>729.63],
  ['site'=>'Harvey','site_id'=>23,'emp'=>'Ramandeep K','period'=>'June 16-30','amount'=>1078.02],
];

// resolve created employees to their ids by name
$created=[];
if ($CREATE){
  $in=implode(',',array_fill(0,count($CREATE),'?'));
  $q=$pdo->prepare("SELECT id,Name FROM users WHERE Name IN ($in)");
  $q->execute(array_keys($CREATE));
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r) $created[$r['Name']]=(int)$r['id'];
}
function uid($emp,$site,$MAP,$CREATE,$created){
  $k="$emp|$site";
  if(isset($MAP[$k])) return $MAP[$k];
  if(isset($CREATE[$emp])) return $created[$emp] ?? null;
  return null;
}

// flexible period -> [start,end]; Sep-Dec=2025, Jan-Jun=2026
function pdates($lbl){
  $lbl=trim($lbl);
  $mm=['jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'may'=>5,'jun'=>6,'jul'=>7,'aug'=>8,'sep'=>9,'sept'=>9,'oct'=>10,'nov'=>11,'dec'=>12];
  // "Mon D-D"  (handles full month names like June, Sept via 3-letter prefix)
  if(preg_match('/([A-Za-z]+)\s*(\d+)\s*-\s*(\d+)/',$lbl,$m)){
    $mon=strtolower(substr($m[1],0,4)); // 'sept'
    if(!isset($mm[$mon])) $mon=substr($mon,0,3); // 'jun','sep'
    if(!isset($mm[$mon])) return[null,null];
    $mo=$mm[$mon]; $yr=$mo>=9?2025:2026; $d1=(int)$m[2]; $d2=(int)$m[3];
    $last=(int)date('t',mktime(0,0,0,$mo,1,$yr));
    if($d1>$last)$d1=$last; if($d2>$last)$d2=$last;  // clamp impossible days (e.g. June 31 -> 30)
    return [sprintf('%04d-%02d-%02d',$yr,$mo,$d1),sprintf('%04d-%02d-%02d',$yr,$mo,$d2)];
  }
  // "D-Mon" (e.g. 30-Jun, 7-May, 1-May) -> treat as a point in that month
  if(preg_match('/(\d+)\s*-\s*([A-Za-z]+)/',$lbl,$m)){
    $mon=strtolower($m[2]); if(!isset($mm[$mon]))return[null,null];
    $mo=$mm[$mon]; $yr=$mo>=9?2025:2026; $d=(int)$m[1];
    $last=(int)date('t',mktime(0,0,0,$mo,1,$yr));
    // half-month: if day index small treat as first half else second
    $s = $d<=2 ? sprintf('%04d-%02d-01',$yr,$mo) : sprintf('%04d-%02d-16',$yr,$mo);
    $e = $d<=2 ? sprintf('%04d-%02d-15',$yr,$mo) : sprintf('%04d-%02d-%02d',$yr,$mo,$last);
    return [$s,$e];
  }
  // "Paid on June 16" style -> that month
  if(preg_match('/([A-Za-z]+)\s+(\d+)/',$lbl,$m)){
    $mon=strtolower($m[1]); if(isset($mm[$mon])){
      $mo=$mm[$mon]; $yr=$mo>=9?2025:2026; $last=(int)date('t',mktime(0,0,0,$mo,1,$yr));
      return [sprintf('%04d-%02d-01',$yr,$mo),sprintf('%04d-%02d-%02d',$yr,$mo,$last)];
    }
  }
  return [null,null];
}

$rates=pe_rates($pdo);
$ins=$pdo->prepare(
 "INSERT INTO payroll_finalized
   (attendance_id,category,display_label,user_id,country_code,org_id,site_id,
    work_date,pay_period_end,reg_hours,overtime_hours,travel_hours,hourly_rate,
    reg_pay,total_gross,cpp,ei,income_tax,cpp_er,ei_er,wcb,vac,
    status,pay_type,paid_amount,payment_status,comments,finalized_by,finalized_at)
  VALUES (0,?,?,?,'CA',2,?,?,?,0,0,0,0,0,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");

echo "<pre style='font:13px monospace'>";
echo "MODE: <b>".strtoupper($MODE)."</b>".($ONLY?" | SITE: $ONLY":"")."\n".str_repeat('=',100)."\n";
$nowe=0;$npay=0;$nskip=0;$tg=0;$tp=0;$unm=[];$paylist=[];

// GUARD: refuse to re-import a site that already has imported rows (prevents refresh duplication)
if($MODE==='live'){
  $chk=$pdo->prepare("SELECT COUNT(*) FROM payroll_finalized WHERE comments LIKE 'Imported back pay%' AND site_id IN (SELECT DISTINCT site_id FROM (SELECT ? AS site_id) t)");
  // simpler: check against the sites present in this run
  $sitesInRun=array_unique(array_map(function($x){return $x['site_id'];}, array_filter($LINES, function($x) use($ONLY){return !$ONLY || $x['site']===$ONLY;})));
  if($sitesInRun){
    $in=implode(',',array_fill(0,count($sitesInRun),'?'));
    $q=$pdo->prepare("SELECT site_id,COUNT(*) c FROM payroll_finalized WHERE comments LIKE 'Imported back pay%' AND site_id IN ($in) GROUP BY site_id");
    $q->execute(array_values($sitesInRun));
    $existing=$q->fetchAll(PDO::FETCH_ASSOC);
    if($existing){
      echo "<pre style='font:13px monospace;color:#b91c1c'>";
      echo "*** IMPORT BLOCKED ***\n\nThis site already has imported rows:\n";
      foreach($existing as $e) echo "  site_id {$e['site_id']}: {$e['c']} rows already imported\n";
      echo "\nIf this is a re-run you did NOT intend, do nothing.\n";
      echo "If you truly need to re-import, first delete existing rows for this site:\n";
      echo "  DELETE FROM payroll_finalized WHERE comments LIKE 'Imported back pay%' AND site_id=<ID>;\n";
      echo "</pre>";
      exit;
    }
  }
}
if($MODE==='live') $pdo->beginTransaction();
foreach($LINES as $L){
  if($ONLY && $L['site']!==$ONLY) continue;
  $u=uid($L['emp'],$L['site'],$MAP,$CREATE,$created);
  if(!$u){ $unm[$L['emp'].'|'.$L['site']]=1; $nskip++; continue; }
  [$s,$e]=pdates($L['period']);
  if($L['amount']<0 && !$s){ $s='2026-01-01'; $e='2026-01-15'; } // undated cash -> Jan
  if(!$s){ echo "SKIP bad date: {$L['emp']} '{$L['period']}'\n"; $nskip++; continue; }
  $amt=(float)$L['amount'];
  if($amt<0){
    // Cash already paid: remember it, apply against this user's owed lines AFTER all owed rows exist.
    $pay=abs($amt); $tp+=$pay;
    $paylist[]=['uid'=>$u,'pay'=>$pay,'period'=>$L['period'],'emp'=>$L['emp']];
    echo sprintf("PAYMENT  %-16s uid=%-6d %-14s -\$%.2f cash (to allocate)\n",$L['emp'],$u,$L['period'],$pay); $npay++;
    continue;
  }
  $vr=pe_vacation_rate($pdo,$u,$L['site_id'],$s);
  $d=pe_line($amt,$vr,$rates); $tg+=$amt;
  echo sprintf("OWE      %-16s uid=%-6d %-14s gross \$%-9.2f net \$%.2f\n",$L['emp'],$u,$L['period'],$amt,$d['net']);
  if($MODE==='live'){
    $ins->execute(['Historical','Back pay ('.$L['period'].')',$u,$L['site_id'],$s,$e,$amt,
      $d['cpp'],$d['ei'],$d['income_tax'],$d['cpp_er'],$d['ei_er'],$d['wcb'],$d['vac'],
      'pending','Regular',0.00,'Unpaid','Imported back pay '.date('Y-m-d'),(int)($_SESSION['user_id']??0)]);
  }
  $nowe++;
}
// ---- allocate cash payments: EXACT single-line match first, else FIFO oldest-first ----
if($MODE==='live' && $paylist){
  foreach($paylist as $P){
    $u=$P['uid']; $pay=$P['pay'];
    // this user's imported owed lines, with net = gross - ee deductions
    $rq=$pdo->prepare("SELECT id,total_gross,cpp,ei,income_tax,paid_amount,payment_status
                       FROM payroll_finalized
                       WHERE user_id=? AND comments LIKE 'Imported back pay%'
                       ORDER BY work_date ASC");
    $rq->execute([$u]);
    $rows=$rq->fetchAll(PDO::FETCH_ASSOC);
    // compute remaining-net per row
    $nets=[]; foreach($rows as $r){
      $net=(float)$r['total_gross']-$r['cpp']-$r['ei']-$r['income_tax'];
      $nets[$r['id']]=['net'=>$net,'paid'=>(float)$r['paid_amount']];
    }
    // 1) exact match: an unpaid row whose net == pay (within 1 cent)
    $matched=null;
    foreach($rows as $r){
      $n=$nets[$r['id']];
      if($n['paid']<0.01 && abs($n['net']-$pay)<0.01){ $matched=$r['id']; break; }
    }
    if($matched!==null){
      $pdo->prepare("UPDATE payroll_finalized SET paid_amount=?,payment_status='Paid',last_paid_date=CURDATE() WHERE id=?")
          ->execute([$pay,$matched]);
    } else {
      // 2) FIFO oldest-first; any leftover cash becomes an OVERPAYMENT on the last line (credit)
      $rem=$pay;
      $lastid=null;
      foreach($rows as $r){
        $lastid=$r['id'];
        if($rem<=0) continue;
        $n=$nets[$r['id']];
        $owed=$n['net']-$n['paid'];
        if($owed<=0.01) continue;
        $apply=min($rem,$owed);
        $newpaid=$n['paid']+$apply;
        $st=$newpaid>=$n['net']-0.01 ? 'Paid':'Partial';
        $pdo->prepare("UPDATE payroll_finalized SET paid_amount=?,payment_status=?,last_paid_date=CURDATE() WHERE id=?")
            ->execute([$newpaid,$st,$r['id']]);
        $rem-=$apply;
      }
      // leftover cash -> add to last line's paid_amount as overpayment (negative remaining balance)
      if($rem>0.01 && $lastid!==null){
        $cur=$nets[$lastid];
        $newpaid=$cur['net']+$rem; // now paid > net => credit
        $pdo->prepare("UPDATE payroll_finalized SET paid_amount=?,payment_status='Paid',last_paid_date=CURDATE() WHERE id=?")
            ->execute([$newpaid,$lastid]);
      }
    }
  }
}
if($MODE==='live') $pdo->commit();

echo str_repeat('=',100)."\n";
echo "Owed rows: <b>$nowe</b>  Cash payments: <b>$npay</b>  Skipped: <b>$nskip</b>\n";
echo "Gross owed: <b>\$".number_format($tg,2)."</b>  Cash applied: <b>\$".number_format($tp,2)."</b>\n";
if($unm){echo "\nUNMATCHED (run create-employees SQL first?):\n";foreach(array_keys($unm) as $x)echo "  $x\n";}
if($MODE==='dry')echo "\n*** DRY RUN — nothing written. Add &mode=live to import. ***\n";
echo "</pre>";