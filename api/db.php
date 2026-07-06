<?php
/**
 * KasirKu - Koneksi database & fungsi bantu keamanan.
 * File ini tidak boleh diakses langsung dari browser (lihat .htaccess).
 */

require_once __DIR__ . '/config.php';

if (!defined('KASIRKU_API')) {
    http_response_code(403);
    exit('Forbidden');
}

/** Tolak akses ke endpoint sync jika aplikasi belum diaktivasi/instalasi. */
function require_installed(): void {
    if (!IS_INSTALLED || !HAS_DB_CONFIG) {
        json_fail('Aplikasi belum diaktivasi. Buka aplikasi dan selesaikan layar Aktivasi Pemilik terlebih dahulu.', 503);
    }
}

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    try {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        error_log('DB connection failed: ' . $e->getMessage());
        json_fail('Koneksi database gagal. Periksa kredensial di api/config.php.', 500);
    }
}

function send_cors_headers(): void {
    header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Api-Key');
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function log_access(string $action, bool $success): void {
    try {
        $stmt = db()->prepare(
            'INSERT INTO sync_access_log (action, ip_address, success) VALUES (?, ?, ?)'
        );
        $stmt->execute([$action, client_ip(), $success ? 1 : 0]);
    } catch (Throwable $e) {
        // Jangan sampai kegagalan logging mengganggu response utama.
    }
}

function require_api_key(string $action = 'unknown'): void {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $sent = $headers['X-Api-Key'] ?? $headers['X-API-Key'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? '');
    if (!is_string($sent) || $sent === '' || !hash_equals(API_KEY, $sent)) {
        log_access($action, false);
        json_fail('API key tidak valid.', 401);
    }
}

function read_json_body(): array {
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len > MAX_PAYLOAD_KB * 1024) {
        json_fail('Ukuran data terlalu besar.', 413);
    }
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_fail('Format data tidak valid (JSON rusak).', 400);
    }
    return $data;
}

function json_ok(array $data = []): void {
    echo json_encode(['ok' => true] + $data);
    exit;
}

function json_fail(string $error, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $error]);
    exit;
}

/** Bersihkan & batasi panjang string dari input klien. */
function clean_str($v, int $maxLen = 255): ?string {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '') return null;
    return mb_substr($s, 0, $maxLen);
}

function clean_num($v): float {
    return is_numeric($v) ? (float)$v : 0.0;
}

function clean_int($v): int {
    return is_numeric($v) ? (int)$v : 0;
}
