<?php
foreach (['FPDF'=>'fpdf/fpdf.php','TCPDF'=>'tcpdf/tcpdf.php','mPDF'=>'mpdf/mpdf.php','vendor'=>'vendor/autoload.php'] as $name=>$path){
    echo $path . ' : ' . (file_exists(__DIR__.'/'.$path) ? "FOUND" : "no") . "<br>";
}
echo "<hr>Searching subfolders for fpdf.php / tcpdf.php / mpdf...<br>";
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $n = strtolower($f->getFilename());
    if (in_array($n, ['fpdf.php','tcpdf.php','mpdf.php']) || $n==='autoload.php') {
        echo $f->getPathname() . "<br>";
    }
}
echo "<hr>Done.";