<?php
/**
 * ============================================================
 * KasirKu - Installer / Aktivasi Pemilik (jalan HANYA SEKALI)
 * ============================================================
 * Endpoint ini dipanggil otomatis oleh layar "Aktivasi Akun Pemilik"
 * di index.html saat aplikasi pertama kali dipakai (belum ada file
 * api/install.lock).
 *
 * Alur:
 *   GET  ?action=status   -> { installed: bool }  (aman diakses publik,
 *                             tidak membocorkan kredensial apa pun)
 *   POST (JSON body)      -> menjalankan instalasi:
 *        1. Terima kredensial database yang diketik MANUAL oleh pemilik
 *           (host, nama database, user, password) + PIN pemilik.
 *        2. Uji koneksi ke database tersebut.
 *        3. Jalankan seluruh isi database.sql ke database itu (membuat
 *           tabel yang belum ada — aman dijalankan berkali-kali karena
 *           memakai "CREATE TABLE IF NOT EXISTS").
 *        4. Migrasi lunak: tambahkan kolom store_id ke tabel transactions
 *           jika database lama belum punya kolom tsb.
 *        5. Buat API_KEY acak, tulis semua kredensial + API_KEY ke
 *           file ".env" (menggantikan config.php yang lama, yang dulu
 *           berisi kredensial polos).
 *        6. Simpan akun Pemilik (PIN sudah di-hash SHA-256 di sisi
 *           aplikasi) ke tabel owner_account.
 *        7. Buat file "install.lock" -> SETELAH INI, endpoint ini akan
 *           SELALU menolak instalasi baru (terkunci permanen) sampai
 *           file install.lock dihapus manual oleh admin lewat
 *           cPanel File Manager / FTP. Ini yang membuat instalasi
 *           "hanya boleh terjadi satu kali".
 *
 * KEAMANAN: endpoint ini TIDAK memerlukan API key (karena saat instalasi
 * pertama, API key itu sendiri belum ada / belum diketahui aplikasi).
 * Sebagai gantinya, endpoint ini mengunci dirinya sendiri secara permanen
 * begitu instalasi pertama berhasil, sehingga tidak bisa dipakai lagi
 * oleh siapa pun setelahnya — termasuk oleh pemilik sendiri, kecuali
 * lewat akses langsung ke server (hapus install.lock).
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
// Endpoint ini perlu bisa dipanggil dari halaman aplikasi manapun sebelum
// ALLOWED_ORIGIN sempat dikonfigurasi, jadi CORS dibuka untuk endpoint ini saja.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function install_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function install_ok(array $data = []): void {
    echo json_encode(['ok' => true] + $data);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? ($method === 'GET' ? 'status' : 'install');

if ($action === 'status') {
    install_ok([
        'installed'   => IS_INSTALLED,
        'hasDbConfig' => HAS_DB_CONFIG,
    ]);
}

if ($action !== 'install' || $method !== 'POST') {
    install_fail('Aksi tidak dikenal.', 400);
}

// --- 1. Tolak permanen jika sudah pernah terpasang ---
if (IS_INSTALLED) {
    install_fail(
        'Aplikasi sudah diaktifkan sebelumnya dan instalasi telah dikunci. ' .
        'Jika Anda memang bermaksud memasang ulang dari nol, hapus file ' .
        '"api/install.lock" secara manual lewat File Manager/FTP di server, ' .
        'lalu muat ulang aplikasi.',
        403
    );
}

// --- 2. Ambil & validasi input ---
$raw = file_get_contents('php://input');
$body = json_decode((string)$raw, true);
if (!is_array($body)) install_fail('Format data tidak valid.', 400);

$dbHost = trim((string)($body['dbHost'] ?? ''));
$dbName = trim((string)($body['dbName'] ?? ''));
$dbUser = trim((string)($body['dbUser'] ?? ''));
$dbPass = (string)($body['dbPass'] ?? '');
$ownerPinHash = trim((string)($body['ownerPinHash'] ?? ''));
$shopName = trim((string)($body['shopName'] ?? '')) ?: 'Toko Saya';

if ($dbHost === '' || $dbName === '' || $dbUser === '') {
    install_fail('Host, nama database, dan user database wajib diisi.', 400);
}
if (!preg_match('/^[a-fA-F0-9]{16,128}$/', $ownerPinHash)) {
    install_fail('PIN pemilik tidak valid (harap aktivasi ulang dari aplikasi).', 400);
}

// --- 3. Uji koneksi ke database ---
try {
    $dsn = 'mysql:host=' . $dbHost . ';dbname=' . $dbName . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 8,
    ]);
} catch (PDOException $e) {
    install_fail('Gagal terhubung ke database. Periksa kembali host/nama database/user/password. (' . $e->getMessage() . ')', 400);
}

// --- 4. Jalankan database.sql (auto-install tabel jika belum ada) ---
$sqlPath = __DIR__ . '/database.sql';
if (!is_file($sqlPath)) install_fail('File database.sql tidak ditemukan di server.', 500);
$sql = file_get_contents($sqlPath);
if ($sql === false) install_fail('Gagal membaca file database.sql.', 500);

// Pisahkan menjadi statement per ";" di akhir baris (parser sederhana, cukup
// untuk struktur database.sql bawaan KasirKu yang tidak memakai ";" di dalam string).
$statements = array_filter(array_map('trim', explode(";\n", str_replace("\r\n", "\n", $sql))));
try {
    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '' || strpos($stmt, '--') === 0) continue;
        $pdo->exec($stmt);
    }
} catch (PDOException $e) {
    install_fail('Gagal membuat struktur tabel database: ' . $e->getMessage(), 500);
}

// --- 5. Migrasi lunak: pastikan kolom store_id ada di tabel transactions lama ---
try {
    $col = $pdo->query(
        "SELECT COUNT(*) c FROM information_schema.COLUMNS " .
        "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='transactions' AND COLUMN_NAME='store_id'"
    )->fetch();
    if (!$col || (int)$col['c'] === 0) {
        $pdo->exec("ALTER TABLE `transactions` ADD COLUMN `store_id` VARCHAR(64) NOT NULL DEFAULT 'default'");
        $pdo->exec("ALTER TABLE `transactions` ADD KEY `idx_trx_store` (`store_id`)");
    }
} catch (PDOException $e) {
    // Non-fatal: instalasi tetap dilanjutkan walau migrasi kolom lama gagal.
}

// --- 6. Generate API key acak & tulis file .env ---
$apiKey = 'KSRK_' . bin2hex(random_bytes(24));
$envContent = "# File ini dibuat OTOMATIS oleh installer KasirKu pada " . date('c') . "\n"
    . "# JANGAN unggah/bagikan file ini ke tempat publik.\n"
    . "DB_HOST=" . $dbHost . "\n"
    . "DB_NAME=" . $dbName . "\n"
    . "DB_USER=" . $dbUser . "\n"
    . "DB_PASS=" . $dbPass . "\n"
    . "API_KEY=" . $apiKey . "\n"
    . "ALLOWED_ORIGIN=*\n"
    . "MAX_PAYLOAD_KB=4096\n";

$envPath = __DIR__ . '/.env';
if (@file_put_contents($envPath, $envContent) === false) {
    install_fail('Gagal menulis file .env. Periksa izin tulis (permission) folder api/ di server.', 500);
}
@chmod($envPath, 0600);

// --- 7. Simpan akun Pemilik & toko default ke database ---
try {
    $pdo->prepare('INSERT INTO owner_account (id, pin_hash) VALUES (1, ?) ON DUPLICATE KEY UPDATE pin_hash = VALUES(pin_hash)')
        ->execute([$ownerPinHash]);

    $pdo->prepare('INSERT INTO settings (id, shop_name) VALUES (1, ?) ON DUPLICATE KEY UPDATE shop_name = VALUES(shop_name)')
        ->execute([$shopName]);

    $pdo->prepare('INSERT INTO stores (id, name, position) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE name = VALUES(name)')
        ->execute(['default', $shopName]);
} catch (PDOException $e) {
    install_fail('Database & .env berhasil dibuat, tetapi gagal menyimpan akun pemilik: ' . $e->getMessage(), 500);
}

// --- 8. Kunci instalasi secara permanen ---
$lockContent = json_encode([
    'installed_at' => date('c'),
    'ip'           => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
]);
if (@file_put_contents(INSTALL_LOCK_FILE, $lockContent) === false) {
    install_fail('Instalasi berhasil tetapi gagal membuat kunci instalasi (install.lock). Buat file kosong bernama "install.lock" di folder api/ secara manual demi keamanan.', 500);
}

install_ok([
    'apiKey'   => $apiKey,
    'shopName' => $shopName,
    'message'  => 'Instalasi berhasil. Database & akun pemilik telah dibuat, dan instalasi telah dikunci.',
]);
