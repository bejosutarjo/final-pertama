<?php
/**
 * KasirKu - Konfigurasi runtime untuk front-end (index.html).
 *
 * Endpoint publik (tanpa API key) yang HANYA mengembalikan:
 *   - status instalasi (installed: true/false)
 *   - API key sinkronisasi (HANYA jika sudah terinstal)
 *
 * Catatan jujur soal keamanan: API key di sini pada akhirnya tetap akan
 * dikirim ke browser agar aplikasi bisa memakainya, persis seperti versi
 * sebelumnya yang menanam API key langsung di index.html. Ini BUKAN celah
 * baru — hanya cara yang lebih rapi untuk mengelolanya lewat .env di server
 * dibanding menempelnya permanen di kode sumber. Untuk keamanan sungguhan,
 * diperlukan sesi login sisi-server (lihat README_INSTALL.md).
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
header('X-Content-Type-Options: nosniff');

echo json_encode([
    'ok'        => true,
    'installed' => IS_INSTALLED,
    'apiKey'    => IS_INSTALLED ? API_KEY : null,
]);
