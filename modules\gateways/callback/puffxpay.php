<?php
use WHMCS\Database\Capsule;

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

$gatewayModuleName = "puffxpay";
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams["type"]) {
    die("Module Not Activated");
}

$data = $_POST;

file_put_contents(__DIR__ . '/puffxpay_callback_log.txt', date('Y-m-d H:i:s') . " => " . print_r($data, true) . PHP_EOL, FILE_APPEND);

$orderId = $data['order_id'] ?? '';
$amount = $data['amount'] ?? 0;
$status = $data['status'] ?? '';
$txn_id = $orderId;

if (!preg_match('/WHMCS_(\d+)_/', $orderId, $matches)) {
    logTransaction($gatewayParams["name"], $data, "Invalid Order ID Format");
    die("Invalid Order");
}

$invoiceId = $matches[1];

// ✅ Validate invoice
checkCbInvoiceID($invoiceId, $gatewayParams['name']);

// ❌ Temporarily skip duplicate check and amount check
// checkCbTransID($txn_id);

// ✅ Try to apply payment
if (strtolower($status) === 'success' || strtoupper($status) === 'SUCCESS') {
    addInvoicePayment($invoiceId, $txn_id, $amount, 0, $gatewayModuleName);
    logTransaction($gatewayParams["name"], $data, "Successful");
    echo "OK";
} else {
    logTransaction($gatewayParams["name"], $data, "Failed");
    echo "FAILED";
}
