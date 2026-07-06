<?php
/**
 * KasirKu - Endpoint sinkronisasi database.
 *
 * POST /api/sync.php          -> kirim (push) data dari aplikasi kasir ke database
 * GET  /api/sync.php?action=pull -> ambil (pull) seluruh data dari database
 *
 * Semua request WAJIB menyertakan header: X-Api-Key: <API_KEY dari config.php>
 */

define('KASIRKU_API', true);
require_once __DIR__ . '/db.php';

send_cors_headers();
require_installed();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? ($method === 'POST' ? 'push' : 'pull');

if ($action === 'pull') {
    require_api_key('pull');
    handle_pull();
} elseif ($action === 'push') {
    require_api_key('push');
    handle_push();
} else {
    json_fail('Aksi tidak dikenal.', 400);
}

// =====================================================================
function handle_pull(): void {
    $pdo = db();

    $products = $pdo->query('SELECT * FROM products ORDER BY name ASC')->fetchAll();
    $products = array_map(function ($p) {
        return [
            'id'                 => $p['id'],
            'name'               => $p['name'],
            'category'           => $p['category'],
            'barcode'            => $p['barcode'],
            'cost'               => (float)$p['cost'],
            'price'              => (float)$p['price'],
            'stock'              => (int)$p['stock'],
            'stockOld'           => $p['stock_old'] !== null ? (int)$p['stock_old'] : null,
            'stockAfterRestock'  => $p['stock_after_restock'] !== null ? (int)$p['stock_after_restock'] : null,
            'lastRestockAt'      => $p['last_restock_at'] !== null ? (int)$p['last_restock_at'] : null,
        ];
    }, $products);

    $stores = $pdo->query('SELECT id, name, address FROM stores ORDER BY position ASC')->fetchAll();

    $trxRows = $pdo->query('SELECT * FROM transactions ORDER BY `timestamp` DESC LIMIT 2000')->fetchAll();
    $itemsStmt = $pdo->prepare('SELECT name, price, qty, cost, subtotal FROM transaction_items WHERE transaction_id = ?');
    $transactions = [];
    foreach ($trxRows as $t) {
        $itemsStmt->execute([$t['id']]);
        $items = $itemsStmt->fetchAll();
        $transactions[] = [
            'id'         => $t['id'],
            'storeId'    => $t['store_id'] ?? 'default',
            'timestamp'  => (int)$t['timestamp'],
            'items'      => array_map(function ($i) {
                return [
                    'name'     => $i['name'],
                    'price'    => (float)$i['price'],
                    'qty'      => (int)$i['qty'],
                    'cost'     => (float)$i['cost'],
                    'subtotal' => (float)$i['subtotal'],
                ];
            }, $items),
            'subtotal'   => (float)$t['subtotal'],
            'discount'   => (float)$t['discount'],
            'total'      => (float)$t['total'],
            'paid'       => (float)$t['paid'],
            'change'     => (float)$t['change_amount'],
            'method'     => $t['payment_method'],
            'kasirName'  => $t['kasir_name'],
            'address'    => $t['shop_address'],
        ];
    }

    $settings = $pdo->query('SELECT * FROM settings WHERE id = 1')->fetch() ?: [];
    $banners = $pdo->query('SELECT text FROM promo_banners ORDER BY position ASC')->fetchAll();
    $owner = $pdo->query('SELECT pin_hash FROM owner_account WHERE id = 1')->fetch();
    $kasirs = $pdo->query('SELECT id, name, pin_hash FROM kasir_accounts')->fetchAll();

    json_ok(['data' => [
        'products'         => $products,
        'stores'           => array_map(function ($s) {
            return ['id' => $s['id'], 'name' => $s['name'], 'address' => $s['address']];
        }, $stores),
        'transactions'     => $transactions,
        'shopName'         => $settings['shop_name'] ?? null,
        'shopAddress'      => $settings['shop_address'] ?? null,
        'shopLogo'         => $settings['shop_logo'] ?? null,
        'printPaperWidth'  => $settings['print_paper_width'] ?? null,
        'promoEnabled'     => isset($settings['promo_enabled']) ? (bool)$settings['promo_enabled'] : null,
        'promoBanners'     => array_column($banners, 'text'),
        'ownerAccount'     => $owner ? ['pinHash' => $owner['pin_hash']] : null,
        'kasirAccounts'    => array_map(function ($k) {
            return ['id' => $k['id'], 'name' => $k['name'], 'pinHash' => $k['pin_hash']];
        }, $kasirs),
    ]]);
}

// =====================================================================
function handle_push(): void {
    $pdo = db();
    $body = read_json_body();

    $pdo->beginTransaction();
    try {
        if (isset($body['products']) && is_array($body['products'])) {
            sync_products($pdo, $body['products']);
        }
        if (isset($body['transactions']) && is_array($body['transactions'])) {
            sync_transactions($pdo, $body['transactions']);
        }
        if (isset($body['stockLogs']) && is_array($body['stockLogs'])) {
            sync_stock_logs($pdo, $body['stockLogs']);
        }
        if (isset($body['stores']) && is_array($body['stores'])) {
            sync_stores($pdo, $body['stores']);
        }
        sync_settings($pdo, $body);
        if (isset($body['promoBanners']) && is_array($body['promoBanners'])) {
            sync_promo_banners($pdo, $body['promoBanners']);
        }
        if (array_key_exists('ownerAccount', $body)) {
            sync_owner_account($pdo, $body['ownerAccount']);
        }
        if (isset($body['kasirAccounts']) && is_array($body['kasirAccounts'])) {
            sync_kasir_accounts($pdo, $body['kasirAccounts']);
        }
        if (isset($body['kasBuka']) && is_array($body['kasBuka'])) {
            sync_kas_buka($pdo, $body['kasBuka']);
        }

        $pdo->commit();
        log_access('push', true);
        json_ok(['synced_at' => date('c')]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Sync push failed: ' . $e->getMessage());
        log_access('push', false);
        json_fail('Gagal menyimpan data ke database.', 500);
    }
}

function sync_products(PDO $pdo, array $products): void {
    // Mirror penuh: produk yang sudah dihapus di aplikasi juga dihapus dari database.
    $stmt = $pdo->prepare(
        'INSERT INTO products (id, name, category, barcode, cost, price, stock, stock_old, stock_after_restock, last_restock_at)
         VALUES (?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
           name = VALUES(name), category = VALUES(category), barcode = VALUES(barcode),
           cost = VALUES(cost), price = VALUES(price), stock = VALUES(stock),
           stock_old = VALUES(stock_old), stock_after_restock = VALUES(stock_after_restock),
           last_restock_at = VALUES(last_restock_at)'
    );
    $keepIds = [];
    foreach ($products as $p) {
        $id = clean_str($p['id'] ?? null, 64);
        if (!$id) continue;
        $keepIds[] = $id;
        $stmt->execute([
            $id,
            clean_str($p['name'] ?? '', 190) ?? '(tanpa nama)',
            clean_str($p['category'] ?? null, 100),
            clean_str($p['barcode'] ?? null, 100),
            clean_num($p['cost'] ?? 0),
            clean_num($p['price'] ?? 0),
            clean_int($p['stock'] ?? 0),
            isset($p['stockOld']) && is_numeric($p['stockOld']) ? (int)$p['stockOld'] : null,
            isset($p['stockAfterRestock']) && is_numeric($p['stockAfterRestock']) ? (int)$p['stockAfterRestock'] : null,
            isset($p['lastRestockAt']) && is_numeric($p['lastRestockAt']) ? (int)$p['lastRestockAt'] : null,
        ]);
    }
    if (!empty($keepIds)) {
        $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
        $pdo->prepare("DELETE FROM products WHERE id NOT IN ($placeholders)")->execute($keepIds);
    }
}

function sync_transactions(PDO $pdo, array $transactions): void {
    $stmtTrx = $pdo->prepare(
        'INSERT INTO transactions (id, store_id, `timestamp`, subtotal, discount, total, paid, change_amount, payment_method, kasir_name, shop_address)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
           store_id=VALUES(store_id), subtotal=VALUES(subtotal), discount=VALUES(discount), total=VALUES(total),
           paid=VALUES(paid), change_amount=VALUES(change_amount), payment_method=VALUES(payment_method),
           kasir_name=VALUES(kasir_name), shop_address=VALUES(shop_address)'
    );
    $stmtDelItems = $pdo->prepare('DELETE FROM transaction_items WHERE transaction_id = ?');
    $stmtItem = $pdo->prepare(
        'INSERT INTO transaction_items (transaction_id, name, price, qty, cost, subtotal) VALUES (?,?,?,?,?,?)'
    );

    foreach ($transactions as $t) {
        $id = clean_str($t['id'] ?? null, 64);
        if (!$id) continue;
        $stmtTrx->execute([
            $id,
            clean_str($t['storeId'] ?? null, 64) ?? 'default',
            clean_int($t['timestamp'] ?? 0),
            clean_num($t['subtotal'] ?? 0),
            clean_num($t['discount'] ?? 0),
            clean_num($t['total'] ?? 0),
            clean_num($t['paid'] ?? 0),
            clean_num($t['change'] ?? 0),
            clean_str($t['method'] ?? 'tunai', 20),
            clean_str($t['kasirName'] ?? null, 100),
            clean_str($t['address'] ?? null, 255),
        ]);
        $stmtDelItems->execute([$id]);
        if (isset($t['items']) && is_array($t['items'])) {
            foreach ($t['items'] as $it) {
                $stmtItem->execute([
                    $id,
                    clean_str($it['name'] ?? '', 190) ?? '(tanpa nama)',
                    clean_num($it['price'] ?? 0),
                    clean_int($it['qty'] ?? 0),
                    clean_num($it['cost'] ?? 0),
                    clean_num($it['subtotal'] ?? 0),
                ]);
            }
        }
    }
}

function sync_stock_logs(PDO $pdo, array $logs): void {
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO stock_logs (id, product_id, type, qty_before, qty_change, qty_after, occurred_at)
         VALUES (?,?,?,?,?,?,?)'
    );
    $validTypes = ['restock', 'sale', 'adjustment'];
    foreach ($logs as $l) {
        $id = clean_str($l['id'] ?? null, 64);
        $productId = clean_str($l['productId'] ?? null, 64);
        $type = in_array($l['type'] ?? '', $validTypes, true) ? $l['type'] : 'adjustment';
        if (!$id || !$productId) continue;
        $stmt->execute([
            $id, $productId, $type,
            clean_int($l['before'] ?? 0),
            clean_int($l['change'] ?? 0),
            clean_int($l['after'] ?? 0),
            clean_int($l['at'] ?? 0),
        ]);
    }
}

function sync_settings(PDO $pdo, array $body): void {
    $stmt = $pdo->prepare(
        'INSERT INTO settings (id, shop_name, shop_address, shop_logo, print_paper_width, promo_enabled)
         VALUES (1, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           shop_name=VALUES(shop_name), shop_address=VALUES(shop_address), shop_logo=VALUES(shop_logo),
           print_paper_width=VALUES(print_paper_width), promo_enabled=VALUES(promo_enabled)'
    );
    $stmt->execute([
        clean_str($body['shopName'] ?? 'Toko Saya', 190),
        clean_str($body['shopAddress'] ?? null, 255),
        isset($body['shopLogo']) ? (string)$body['shopLogo'] : null,
        clean_str($body['printPaperWidth'] ?? '80', 10),
        !empty($body['promoEnabled']) ? 1 : 0,
    ]);
}

function sync_promo_banners(PDO $pdo, array $banners): void {
    $pdo->exec('DELETE FROM promo_banners');
    $stmt = $pdo->prepare('INSERT INTO promo_banners (text, position) VALUES (?, ?)');
    foreach (array_values($banners) as $idx => $text) {
        $t = clean_str($text, 255);
        if ($t) $stmt->execute([$t, $idx]);
    }
}

function sync_owner_account(PDO $pdo, $owner): void {
    $pinHash = is_array($owner) ? clean_str($owner['pinHash'] ?? null, 128) : null;
    $stmt = $pdo->prepare(
        'INSERT INTO owner_account (id, pin_hash) VALUES (1, ?)
         ON DUPLICATE KEY UPDATE pin_hash = VALUES(pin_hash)'
    );
    $stmt->execute([$pinHash]);
}

function sync_kasir_accounts(PDO $pdo, array $kasirs): void {
    $stmt = $pdo->prepare(
        'INSERT INTO kasir_accounts (id, name, pin_hash) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE name=VALUES(name), pin_hash=VALUES(pin_hash)'
    );
    $keepIds = [];
    foreach ($kasirs as $k) {
        $id = clean_str($k['id'] ?? null, 64);
        if (!$id) continue;
        $keepIds[] = $id;
        $stmt->execute([$id, clean_str($k['name'] ?? '', 150) ?? '(tanpa nama)', clean_str($k['pinHash'] ?? null, 128)]);
    }
    if (!empty($keepIds)) {
        $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
        $pdo->prepare("DELETE FROM kasir_accounts WHERE id NOT IN ($placeholders)")->execute($keepIds);
    } else {
        $pdo->exec('DELETE FROM kasir_accounts');
    }
}

function sync_stores(PDO $pdo, array $stores): void {
    // Mirror penuh: toko yang dihapus di aplikasi juga dihapus dari database,
    // tapi toko "default" tidak pernah dihapus supaya transaksi lama selalu punya induk.
    $stmt = $pdo->prepare(
        'INSERT INTO stores (id, name, address, position) VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE name=VALUES(name), address=VALUES(address), position=VALUES(position)'
    );
    $keepIds = ['default'];
    foreach (array_values($stores) as $idx => $s) {
        $id = clean_str($s['id'] ?? null, 64);
        if (!$id) continue;
        $keepIds[] = $id;
        $stmt->execute([
            $id,
            clean_str($s['name'] ?? '', 190) ?? '(toko tanpa nama)',
            clean_str($s['address'] ?? null, 255),
            $idx,
        ]);
    }
    $keepIds = array_values(array_unique($keepIds));
    $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
    $pdo->prepare("DELETE FROM stores WHERE id NOT IN ($placeholders)")->execute($keepIds);
}

function sync_kas_buka(PDO $pdo, array $kb): void {
    if (empty($kb['tanggal'])) return;
    $stmt = $pdo->prepare(
        'INSERT INTO kas_buka (tanggal, modal_awal, kasir_name, kasir_id) VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE modal_awal=VALUES(modal_awal), kasir_name=VALUES(kasir_name), kasir_id=VALUES(kasir_id)'
    );
    $stmt->execute([
        clean_str($kb['tanggal'], 40),
        clean_num($kb['modalAwal'] ?? 0),
        clean_str($kb['kasirName'] ?? null, 150),
        clean_str($kb['kasirId'] ?? null, 64),
    ]);
}
