<?php
/**
 * ============================================================
 * KasirKu - Konfigurasi Database & Keamanan
 * ============================================================
 * MULAI VERSI INI, kredensial TIDAK LAGI ditulis langsung di file ini.
 * Semua nilai (host, nama database, user, password, API key, dst) dibaca
 * secara otomatis dari file ".env" di folder yang sama (api/.env).
 *
 * File ".env" ini dibuat OTOMATIS oleh installer (install.php) saat
 * pemilik melakukan "Aktivasi" pertama kali dari aplikasi (lihat layar
 * aktivasi pemilik di index.html). Anda tidak perlu mengedit apa pun
 * di sini secara manual dalam kondisi normal.
 *
 * Jika Anda perlu mengatur ulang secara manual, salin file ".env.example"
 * menjadi ".env" lalu isi nilainya, atau hapus "install.lock" dan jalankan
 * ulang aktivasi dari aplikasi.
 */

// --- Muat file .env (parser sederhana, tanpa dependensi composer) ---
function kasirku_load_env(string $path): array {
    $vals = [];
    if (!is_file($path) || !is_readable($path)) return $vals;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return $vals;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $eq = strpos($line, '=');
        if ($eq === false) continue;
        $key = trim(substr($line, 0, $eq));
        $val = trim(substr($line, $eq + 1));
        if (strlen($val) >= 2) {
            $first = $val[0];
            $last = $val[strlen($val) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $val = substr($val, 1, -1);
            }
        }
        if ($key !== '') $vals[$key] = $val;
    }
    return $vals;
}

function env_val(array $env, string $key, $default = null) {
    if (isset($env[$key]) && $env[$key] !== '') return $env[$key];
    $fromServerEnv = getenv($key);
    if ($fromServerEnv !== false && $fromServerEnv !== '') return $fromServerEnv;
    return $default;
}

$__envPath = __DIR__ . '/.env';
$__env = kasirku_load_env($__envPath);

// --- Kredensial database MySQL (dari .env) ---
define('DB_HOST', env_val($__env, 'DB_HOST', ''));
define('DB_NAME', env_val($__env, 'DB_NAME', ''));
define('DB_USER', env_val($__env, 'DB_USER', ''));
define('DB_PASS', env_val($__env, 'DB_PASS', ''));

// --- Kunci rahasia API (dibuat otomatis oleh installer, disimpan di .env) ---
define('API_KEY', env_val($__env, 'API_KEY', ''));

// --- Domain yang diizinkan mengakses API ini (CORS) ---
define('ALLOWED_ORIGIN', env_val($__env, 'ALLOWED_ORIGIN', '*'));

// --- Batas ukuran data per sinkronisasi (KB), mencegah payload raksasa ---
define('MAX_PAYLOAD_KB', (int) env_val($__env, 'MAX_PAYLOAD_KB', 4096));

// --- Status aktivasi/instalasi (dikunci oleh installer setelah instalasi pertama) ---
define('INSTALL_LOCK_FILE', __DIR__ . '/install.lock');
define('IS_INSTALLED', is_file(INSTALL_LOCK_FILE));
define('HAS_DB_CONFIG', DB_HOST !== '' && DB_NAME !== '' && DB_USER !== '');

// --- Jangan tampilkan detail error PHP ke publik ---
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('Asia/Jakarta');
