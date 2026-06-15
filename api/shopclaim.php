<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/database.php';

function formatNullableDate($value, $format = 'd/m/Y') {
    if (empty($value) || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
        return '';
    }

    return date($format, strtotime($value));
}

function normalizeClaimTypeValue($claimType): string {
    return str_replace(' ', '_', strtolower(trim((string) $claimType)));
}

function normalizeStatusValue($status): string {
    $normalizedStatus = strtolower(trim((string) $status));
    return $normalizedStatus === '' ? 'pending' : $normalizedStatus;
}

function buildSyntheticClaimId(int $serviceOrderId, int $sourceProductId): int {
    return ($serviceOrderId * 1000000) + $sourceProductId;
}

function loadSourceProductNameMap(PDO $conn): array {
    $stmt = $conn->prepare("SELECT id, product_name FROM products");
    $stmt->execute();

    $productNameMap = [];
    while ($product = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $productId = (int) ($product['id'] ?? 0);
        if ($productId <= 0) {
            continue;
        }

        $productNameMap[$productId] = trim((string) ($product['product_name'] ?? ''));
    }

    return $productNameMap;
}

function buildShopClaimRow(
    array $order,
    int $sourceProductId,
    array $claimDetails,
    array $sourceProductNameMap
): array {
    $serviceOrderId = (int) ($order['id'] ?? 0);
    $orderStatus = normalizeStatusValue($order['status'] ?? '');
    $sourceProductName = trim((string) ($sourceProductNameMap[$sourceProductId] ?? ''));

    return [
        'id' => buildSyntheticClaimId($serviceOrderId, $sourceProductId),
        'service_order_id' => $serviceOrderId,
        'service_order_code' => (string) ($order['order_code'] ?? ('ORD' . $serviceOrderId)),
        'source_product_id' => $sourceProductId,
        'product_code' => sprintf('SCS%06dP%03d', $serviceOrderId, $sourceProductId),
        'product_name' => trim((string) ($claimDetails['shop_claim_product_name'] ?? '')) ?: ($sourceProductName !== '' ? $sourceProductName : ('Claim Product ' . $sourceProductId)),
        'source_product_name' => $sourceProductName,
        'serial_number' => trim((string) ($claimDetails['shop_claim_serial_number'] ?? '')),
        'is_spare_product' => 0,
        'brand' => trim((string) ($claimDetails['shop_claim_brand'] ?? '')),
        'model' => trim((string) ($claimDetails['shop_claim_model'] ?? '')),
        'category' => 'service',
        'claim_type' => 'shop_claim',
        'shop_claim_product_name' => trim((string) ($claimDetails['shop_claim_product_name'] ?? '')),
        'shop_claim_brand' => trim((string) ($claimDetails['shop_claim_brand'] ?? '')),
        'shop_claim_model' => trim((string) ($claimDetails['shop_claim_model'] ?? '')),
        'shop_claim_serial_number' => trim((string) ($claimDetails['shop_claim_serial_number'] ?? '')),
        'specifications' => (string) ($order['issue_description'] ?? ''),
        'purchase_date' => '',
        'warranty_period' => '',
        'warranty_status' => (string) ($order['warranty_status'] ?? 'active'),
        'price' => '0',
        'stock_quantity' => 0,
        'min_stock_level' => 0,
        'status' => $orderStatus,
        'service_status' => $orderStatus,
        'client_name' => trim((string) ($order['client_name'] ?? '')),
        'client_phone' => trim((string) ($order['client_phone'] ?? '')),
        'created_at' => (string) ($order['created_at'] ?? ''),
        'updated_at' => (string) ($order['updated_at'] ?? ($order['created_at'] ?? '')),
        'total_orders' => 1,
        'purchase_date_formatted' => '',
        'created_at_formatted' => formatNullableDate($order['created_at'] ?? '', 'd/m/Y h:i A'),
        'updated_at_formatted' => formatNullableDate($order['updated_at'] ?? ($order['created_at'] ?? ''), 'd/m/Y h:i A'),
    ];
}

function loadShopClaimRows(PDO $conn): array {
    $query = "SELECT so.id,
                     so.order_code,
                     so.client_id,
                     so.issue_description,
                     so.warranty_status,
                     so.status,
                     so.created_at,
                     so.updated_at,
                     so.selected_product_claim_types,
                     so.selected_product_claim_details,
                     c.full_name AS client_name,
                     c.phone AS client_phone
              FROM service_orders so
              LEFT JOIN clients c ON so.client_id = c.id
              WHERE so.selected_product_claim_types IS NOT NULL
                AND so.selected_product_claim_types <> ''
              ORDER BY so.created_at DESC, so.id DESC";
    $stmt = $conn->prepare($query);
    $stmt->execute();

    $sourceProductNameMap = loadSourceProductNameMap($conn);
    $shopClaimRows = [];

    while ($order = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $claimTypeMap = json_decode((string) ($order['selected_product_claim_types'] ?? ''), true);
        $claimDetailsMap = json_decode((string) ($order['selected_product_claim_details'] ?? ''), true);

        if (!is_array($claimTypeMap)) {
            continue;
        }

        foreach ($claimTypeMap as $productId => $mappedClaimType) {
            $sourceProductId = (int) $productId;
            if ($sourceProductId <= 0) {
                continue;
            }

            if (normalizeClaimTypeValue($mappedClaimType) !== 'shop_claim') {
                continue;
            }

            $claimDetails = [];
            if (isset($claimDetailsMap[$productId]) && is_array($claimDetailsMap[$productId])) {
                $claimDetails = $claimDetailsMap[$productId];
            }

            $shopClaimRows[] = buildShopClaimRow($order, $sourceProductId, $claimDetails, $sourceProductNameMap);
        }
    }

    return $shopClaimRows;
}

function matchesShopClaimSearch(array $product, string $search): bool {
    if ($search === '') {
        return true;
    }

    $needle = strtolower($search);
    $searchableValues = [
        $product['product_name'] ?? '',
        $product['product_code'] ?? '',
        $product['source_product_name'] ?? '',
        $product['serial_number'] ?? '',
        $product['brand'] ?? '',
        $product['model'] ?? '',
        $product['shop_claim_product_name'] ?? '',
        $product['service_order_code'] ?? '',
        $product['service_order_id'] ?? '',
        $product['client_name'] ?? '',
        $product['client_phone'] ?? '',
        $product['status'] ?? '',
    ];

    foreach ($searchableValues as $value) {
        if (strpos(strtolower((string) $value), $needle) !== false) {
            return true;
        }
    }

    return false;
}

try {
    $database = new Database();
    $conn = $database->getConnection();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(["success" => false, "message" => "Method not allowed"]);
        exit();
    }

    $shopClaimRows = loadShopClaimRows($conn);

    if (isset($_GET['id']) && $_GET['id'] !== '') {
        $requestedId = (int) $_GET['id'];
        $matchedProduct = null;

        foreach ($shopClaimRows as $row) {
            if ((int) ($row['id'] ?? 0) === $requestedId) {
                $matchedProduct = $row;
                break;
            }
        }

        if ($matchedProduct === null) {
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "Shop claim service not found"]);
            exit();
        }

        echo json_encode(["success" => true, "product" => $matchedProduct]);
        exit();
    }

    $search = trim((string) ($_GET['search'] ?? ''));
    $statusFilter = normalizeStatusValue($_GET['status'] ?? '');
    $hasStatusFilter = isset($_GET['status']) && trim((string) $_GET['status']) !== '' && trim((string) $_GET['status']) !== 'all';
    $startDate = trim((string) ($_GET['start_date'] ?? ''));
    $endDate = trim((string) ($_GET['end_date'] ?? ''));

    $filteredProducts = array_values(array_filter($shopClaimRows, static function (array $product) use ($search, $hasStatusFilter, $statusFilter, $startDate, $endDate): bool {
        if ($hasStatusFilter && normalizeStatusValue($product['status'] ?? '') !== $statusFilter) {
            return false;
        }

        $createdDate = substr((string) ($product['created_at'] ?? ''), 0, 10);
        if ($startDate !== '' && ($createdDate === '' || $createdDate < $startDate)) {
            return false;
        }

        if ($endDate !== '' && ($createdDate === '' || $createdDate > $endDate)) {
            return false;
        }

        return matchesShopClaimSearch($product, $search);
    }));

    usort($filteredProducts, static function (array $left, array $right): int {
        return strtotime((string) ($right['created_at'] ?? '')) <=> strtotime((string) ($left['created_at'] ?? ''));
    });

    echo json_encode([
        "success" => true,
        "count" => count($filteredProducts),
        "products" => $filteredProducts
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Server error",
        "error" => $e->getMessage()
    ]);
}
?>
