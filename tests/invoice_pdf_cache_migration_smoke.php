<?php
declare(strict_types=1);

require_once __DIR__ . '/../modules/billing/lib/invoice_pdf_cache.php';

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'invoice-pdf-cache-' . bin2hex(random_bytes(6));
$legacy = $base . DIRECTORY_SEPARATOR . 'public_html/storage/billing/invoices';
$private = $base . DIRECTORY_SEPARATOR . 'private_html';
mkdir($legacy . '/7', 0700, true);
mkdir($private, 0700);
$a = '2-' . str_repeat('a', 40) . '.pdf';
$b = '2-' . str_repeat('b', 40) . '.pdf';
$other = '3-' . str_repeat('c', 40) . '.pdf';
file_put_contents($legacy . '/7/' . $a, 'old-a');
file_put_contents($legacy . '/7/' . $b, 'old-b');
file_put_contents($legacy . '/7/' . $other, 'other-invoice');

try {
    $moved = invoiceArchiveLegacyPdfCache(2, 7, $legacy, $private);
    $archive = $private . '/billing/legacy-invoice-cache/7';
    if ($moved !== 2 || is_file($legacy . '/7/' . $a) || is_file($legacy . '/7/' . $b)
        || file_get_contents($archive . '/' . $a) !== 'old-a'
        || file_get_contents($archive . '/' . $b) !== 'old-b'
        || file_get_contents($legacy . '/7/' . $other) !== 'other-invoice'
        || invoiceArchiveLegacyPdfCache(2, 7, $legacy, $private) !== 0) {
        throw new RuntimeException('Invoice-scoped archive was incomplete or not idempotent.');
    }
    file_put_contents($legacy . '/7/' . $a, 'conflicting-copy');
    try {
        invoiceArchiveLegacyPdfCache(2, 7, $legacy, $private);
        throw new RuntimeException('Archive collision was accepted.');
    } catch (RuntimeException $error) {
        if (!str_contains($error->getMessage(), 'conflicting file')) throw $error;
    }
    if (file_get_contents($legacy . '/7/' . $a) !== 'conflicting-copy'
        || file_get_contents($archive . '/' . $a) !== 'old-a') {
        throw new RuntimeException('Archive collision changed source or destination.');
    }
    echo "Passed: legacy invoice PDFs moved privately by invoice, with collision refusal\n";
} finally {
    $resolved = realpath($base);
    $temp = realpath(sys_get_temp_dir());
    if ($resolved === false || $temp === false
        || !str_starts_with($resolved, $temp . DIRECTORY_SEPARATOR . 'invoice-pdf-cache-')) {
        throw new RuntimeException('Refusing cleanup outside the invoice cache fixture.');
    }
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
        if ($file->isDir()) rmdir($file->getPathname());
        else unlink($file->getPathname());
    }
    rmdir($resolved);
}
