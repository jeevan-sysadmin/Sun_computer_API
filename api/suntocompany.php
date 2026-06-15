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

function loadSourceProductMap(PDO $conn): array {
    $stmt = $conn->prepare("SELECT id, product_name, serial_number, brand, model, category FROM products");
    $stmt->execute();

    $productMap = [];
    while ($product = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $productId = (int) ($product['id'] ?? 0);
        if ($productId <= 0) {
            continue;
        }

        $productMap[$productId] = [
            'product_name' => trim((string) ($product['product_name'] ?? '')),
            'serial_number' => trim((string) ($product['serial_number'] ?? '')),
            'brand' => trim((string) ($product['brand'] ?? '')),
            'model' => trim((string) ($product['model'] ?? '')),
            'category' => trim((string) ($product['category'] ?? '')),
        ];
    }

    return $productMap;
}

function buildSunToCompanyRow(array $order, int $sourceProductId, array $sourceProductMap): array {
    $serviceOrderId = (int) ($order['id'] ?? 0);
    $orderStatus = normalizeStatusValue($order['status'] ?? '');
    $sourceProduct = $sourceProductMap[$sourceProductId] ?? [];
    $sourceProductName = trim((string) ($sourceProduct['product_name'] ?? ''));

    return [
        'id' => buildSyntheticClaimId($serviceOrderId, $sourceProductId),
        'service_order_id' => $serviceOrderId,
        'service_order_code' => (string) ($order['order_code'] ?? ('ORD' . $serviceOrderId)),
        'source_product_id' => $sourceProductId,
        'product_code' => sprintf('STC%06dP%03d', $serviceOrderId, $sourceProductId),
        'product_name' => $sourceProductName !== '' ? $sourceProductName : ('Claim Product ' . $sourceProductId),
        'source_product_name' => $sourceProductName,
        'serial_number' => trim((string) ($sourceProduct['serial_number'] ?? '')),
        'is_spare_product' => 0,
        'brand' => trim((string) ($sourceProduct['brand'] ?? '')),
        'model' => trim((string) ($sourceProduct['model'] ?? '')),
        'category' => 'service',
        'claim_type' => 'sun_to_company',
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

function loadSunToCompanyRows(PDO $conn): array {
    $query = "SELECT so.id,
                     so.order_code,
                     so.client_id,
                     so.issue_description,
                     so.warranty_status,
                     so.status,
                     so.created_at,
                     so.updated_at,
                     so.selected_product_claim_types,
                     c.full_name AS client_name,
                     c.phone AS client_phone
              FROM service_orders so
              LEFT JOIN clients c ON so.client_id = c.id
              WHERE so.selected_product_claim_types IS NOT NULL
                AND so.selected_product_claim_types <> ''
              ORDER BY so.created_at DESC, so.id DESC";
    $stmt = $conn->prepare($query);
    $stmt->execute();

    $sourceProductMap = loadSourceProductMap($conn);
    $rows = [];

    while ($order = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $claimTypeMap = json_decode((string) ($order['selected_product_claim_types'] ?? ''), true);
        if (!is_array($claimTypeMap)) {
            continue;
        }

        foreach ($claimTypeMap as $productId => $mappedClaimType) {
            $sourceProductId = (int) $productId;
            if ($sourceProductId <= 0) {
                continue;
            }

            if (normalizeClaimTypeValue($mappedClaimType) !== 'sun_to_company') {
                continue;
            }

            $rows[] = buildSunToCompanyRow($order, $sourceProductId, $sourceProductMap);
        }
    }

    return $rows;
}

function matchesSunToCompanySearch(array $product, string $search): bool {
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

    $rows = loadSunToCompanyRows($conn);

    if (isset($_GET['id']) && $_GET['id'] !== '') {
        $requestedId = (int) $_GET['id'];
        $matchedProduct = null;

        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) === $requestedId) {
                $matchedProduct = $row;
                break;
            }
        }

        if ($matchedProduct === null) {
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "Sun to company service not found"]);
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

    $filteredProducts = array_values(array_filter($rows, static function (array $product) use ($search, $hasStatusFilter, $statusFilter, $startDate, $endDate): bool {
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

        return matchesSunToCompanySearch($product, $search);
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
