<?php
/**
 * Carga las librerias de PDF (FPDF y phpqrcode) para cualquier generador de
 * Representacion Impresa: la de carta (FacturaPdfGenerator, CotizacionPdf-
 * Generator) y la de tirilla POS (ReciboPos80). Un solo lugar donde mirar si
 * manana se mueve el vendor o entra Composer.
 *
 * phpqrcode es opcional a proposito: sin el (o sin GD) el timbre se imprime
 * igual con su codigo de seguridad y su fecha de firma, solo sin la imagen del
 * QR. Una factura ya emitida siempre debe poder reimprimirse.
 */
$fpdfPath = __DIR__ . '/../../../vendor/fpdf/fpdf.php';
$composerPath = __DIR__ . '/../../../vendor/autoload.php';

if (file_exists($composerPath)) {
    require_once $composerPath;
} elseif (file_exists($fpdfPath)) {
    require_once $fpdfPath;
} else {
    throw new RuntimeException('Falta la libreria FPDF (vendor/fpdf/fpdf.php o vendor/autoload.php).');
}

$qrLibPath = __DIR__ . '/../../../vendor/phpqrcode/qrlib.php';
if (file_exists($qrLibPath)) {
    require_once $qrLibPath;
}
