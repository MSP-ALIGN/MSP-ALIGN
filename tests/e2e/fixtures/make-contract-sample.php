<?php
// Makes contract-sample.pdf (a made-up agreement for contracts_e2e). contract-sample-objstm.pdf is the same file with
// compressed object streams: qpdf --object-streams=generate contract-sample.pdf contract-sample-objstm.pdf
// Run: ALIGN_CONFIG=... php tests/e2e/fixtures/make-contract-sample.php tests/e2e/fixtures/contract-sample.pdf
require 'src/bootstrap.php';
use Align\Pdf\Pdf;
$p = new Pdf(612, 792);
$t = fn($x, $y, $s, $f = 'Helvetica', $z = 10) => $p->text($x, $y, $s, $f, $z);
// Page 1
$p->addPage();
$p->rect(60, 40, 492, 36, [0.12, 0.33, 0.47]);
$p->text(72, 64, 'EXAMPLE MSP', 'Helvetica-Bold', 20, [1, 1, 1]);
$t(72, 120, 'Agreement Terms & Conditions', 'Helvetica-Bold', 11);
$t(72, 140, 'This Service Agreement ("Agreement") is made between Example MSP and [                              ],');
$t(72, 154, 'hereinafter referred to as the "Client".');
$t(72, 180, '1. Term:', 'Helvetica-Bold');
$t(72, 196, '- Month-to-month, cancelable with thirty (30) days written notice by either party.');
$t(72, 210, '- The agreement will come into effect on [                  ] and renews monthly.');
$t(72, 236, '2. Payment:', 'Helvetica-Bold');
$t(72, 252, '- Services are paid monthly in advance.');
// Page 2
$p->addPage();
$t(72, 90, 'Exclusions:', 'Helvetica-Bold');
$t(72, 106, '- Projects, replacement parts and third-party licensing are billed separately.');
$t(72, 136, 'Please initial that you have read and understand the exclusions above -');
// Page 3
$p->addPage();
$t(72, 90, 'Statement of Work', 'Helvetica-Bold', 12);
$t(72, 120, 'Service Item          Quantity          Monthly Rate', 'Helvetica-Bold');
foreach (['Users =', 'Workstations =', 'Servers ='] as $i => $l) {
    $t(72, 144 + $i * 20, $l, 'Helvetica-Bold');
    $t(300, 144 + $i * 20, '$', 'Helvetica-Bold');
}
$t(72, 230, 'Minimum Monthly Commitment = $', 'Helvetica-Bold');
$t(72, 290, 'Example MSP:', 'Helvetica-Bold', 11);
$t(330, 290, '[                                    ]', 'Helvetica', 11);
foreach (['SIGNATURE:', 'AUTHORIZED SIGNER:', 'TITLE:', 'DATE:'] as $i => $l) {
    $y = 324 + $i * 30;
    $t(72, $y, $l, 'Helvetica-Bold', 8);
    $t(170, $y, '____________________________', 'Helvetica', 9);
    $t(330, $y, $l, 'Helvetica-Bold', 8);
    $t(428, $y, '____________________________', 'Helvetica', 9);
}
file_put_contents($argv[1], $p->output());
