<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

require_once 'koneksi.php';

$action = $_GET['action'] ?? '';

// 1. AMBIL SEMUA DATA (PRODUCTS, INCOME, EXPENSE)
if ($action == 'get_all') {
    $products = $conn->query("SELECT * FROM products ORDER BY id DESC")->fetch_all(MYSQLI_ASSOC);
    $income   = $conn->query("SELECT i.*, p.name as desc_name FROM income i LEFT JOIN products p ON i.product_id = p.id ORDER BY date DESC, id DESC")->fetch_all(MYSQLI_ASSOC);
    $expense  = $conn->query("SELECT * FROM expense ORDER BY date DESC, id DESC")->fetch_all(MYSQLI_ASSOC);

    echo json_encode([
        'products' => $products,
        'income'   => $income,
        'expense'  => $expense
    ]);
    exit;
}

// 2. KELOLA BARANG & STOK
if ($action == 'add_product') {
    $data = json_decode(file_get_contents("php://input"), true);
    $stmt = $conn->prepare("INSERT INTO products (name, cost, sell, stock) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("sddi", $data['name'], $data['cost'], $data['sell'], $data['stock']);
    $stmt->execute();
    echo json_encode(["status" => "success"]);
    exit;
}

if ($action == 'edit_product') {
    $data = json_decode(file_get_contents("php://input"), true);
    $stmt = $conn->prepare("UPDATE products SET name = ?, cost = ?, sell = ?, stock = ? WHERE id = ?");
    $stmt->bind_param("sddii", $data['name'], $data['cost'], $data['sell'], $data['stock'], $data['id']);
    $stmt->execute();
    echo json_encode(["status" => "success"]);
    exit;
}

if ($action == 'add_stock') {
    $data = json_decode(file_get_contents("php://input"), true);
    $stmt = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
    $stmt->bind_param("ii", $data['qty'], $data['productId']);
    $stmt->execute();
    echo json_encode(["status" => "success"]);
    exit;
}

// 3. KELOLA UANG MASUK (PENJUALAN)
if ($action == 'add_income') {
    $data = json_decode(file_get_contents("php://input"), true);
    $productId = intval($data['productId']);
    $qty = intval($data['qty']);

    // Cek ketersediaan stok di database
    $checkQuery = $conn->query("SELECT stock FROM products WHERE id = $productId");
    $product = $checkQuery->fetch_assoc();

    if (!$product || intval($product['stock']) < $qty) {
        echo json_encode(["status" => "error", "message" => "Stok di database tidak mencukupi!"]);
        exit;
    }

    // Potong stok barang
    $conn->query("UPDATE products SET stock = stock - $qty WHERE id = $productId");

    // Simpan transaksi
    $stmt = $conn->prepare("INSERT INTO income (date, product_id, qty, price, amount) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("siidd", $data['date'], $productId, $qty, $data['price'], $data['amount']);
    $stmt->execute();

    echo json_encode(["status" => "success"]);
    exit;
}

if ($action == 'edit_income') {
    $data = json_decode(file_get_contents("php://input"), true);
    $id = intval($data['id']);
    $newProductId = intval($data['productId']);
    $newQty = intval($data['qty']);

    // Kembalikan stok transaksi lama
    $oldIncome = $conn->query("SELECT product_id, qty FROM income WHERE id = $id")->fetch_assoc();
    if ($oldIncome && $oldIncome['product_id']) {
        $conn->query("UPDATE products SET stock = stock + " . intval($oldIncome['qty']) . " WHERE id = " . intval($oldIncome['product_id']));
    }

    // Cek ketersediaan stok baru
    $checkQuery = $conn->query("SELECT stock FROM products WHERE id = $newProductId");
    $product = $checkQuery->fetch_assoc();

    if (!$product || intval($product['stock']) < $newQty) {
        // Rollback (kembalikan pemotongan lama jika stok baru kurang)
        if ($oldIncome && $oldIncome['product_id']) {
            $conn->query("UPDATE products SET stock = stock - " . intval($oldIncome['qty']) . " WHERE id = " . intval($oldIncome['product_id']));
        }
        echo json_encode(["status" => "error", "message" => "Stok tidak mencukupi untuk jumlah baru ini!"]);
        exit;
    }

    // Potong stok baru
    $conn->query("UPDATE products SET stock = stock - $newQty WHERE id = $newProductId");

    // Update transaksi
    $stmt = $conn->prepare("UPDATE income SET date = ?, product_id = ?, qty = ?, price = ?, amount = ? WHERE id = ?");
    $stmt->bind_param("siiddi", $data['date'], $newProductId, $newQty, $data['price'], $data['amount'], $id);
    $stmt->execute();

    echo json_encode(["status" => "success"]);
    exit;
}

// 4. KELOLA UANG KELUAR
if ($action == 'add_expense') {
    $data = json_decode(file_get_contents("php://input"), true);
    $stmt = $conn->prepare("INSERT INTO expense (date, description, amount) VALUES (?, ?, ?)");
    $stmt->bind_param("ssd", $data['date'], $data['desc'], $data['amount']);
    $stmt->execute();
    echo json_encode(["status" => "success"]);
    exit;
}

if ($action == 'edit_expense') {
    $data = json_decode(file_get_contents("php://input"), true);
    $stmt = $conn->prepare("UPDATE expense SET date = ?, description = ?, amount = ? WHERE id = ?");
    $stmt->bind_param("ssdi", $data['date'], $data['desc'], $data['amount'], $data['id']);
    $stmt->execute();
    echo json_encode(["status" => "success"]);
    exit;
}

// 5. KELOLA SALDO AWAL BULAN
if ($action == 'get_initial_balance') {
    $month = $conn->real_escape_string($_GET['month'] ?? '');
    $res = $conn->query("SELECT amount FROM initial_balance WHERE month_year = '$month'")->fetch_assoc();
    echo json_encode(['amount' => $res ? floatval($res['amount']) : 0]);
    exit;
}

if ($action == 'set_initial_balance') {
    $data = json_decode(file_get_contents("php://input"), true);
    $month = $conn->real_escape_string($data['month']);
    $amount = floatval($data['amount']);

    $stmt = $conn->prepare("INSERT INTO initial_balance (month_year, amount) VALUES (?, ?) ON DUPLICATE KEY UPDATE amount = ?");
    $stmt->bind_param("sdd", $month, $amount, $amount);
    $stmt->execute();

    echo json_encode(["status" => "success"]);
    exit;
}

// 6. HAPUS DATA
if ($action == 'delete') {
    $type = $_GET['type'] ?? '';
    $id   = intval($_GET['id'] ?? 0);

    if ($type == 'income') {
        // Kembalikan stok saat transaksi dihapus
        $item = $conn->query("SELECT product_id, qty FROM income WHERE id = $id")->fetch_assoc();
        if ($item && $item['product_id']) {
            $conn->query("UPDATE products SET stock = stock + " . intval($item['qty']) . " WHERE id = " . intval($item['product_id']));
        }
        $conn->query("DELETE FROM income WHERE id = $id");
    } elseif ($type == 'expense') {
        $conn->query("DELETE FROM expense WHERE id = $id");
    } elseif ($type == 'products') {
        $conn->query("DELETE FROM products WHERE id = $id");
    }

    echo json_encode(["status" => "success"]);
    exit;
}
?>