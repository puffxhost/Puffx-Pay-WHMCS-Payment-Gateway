<?php
use WHMCS\Database\Capsule;

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

$gatewayModuleName = 'puffxpay';
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (empty($gatewayParams['type'])) {
    http_response_code(503);
    exit('Module not activated');
}

$orderId = isset($_POST['order_id']) && is_string($_POST['order_id'])
    ? trim($_POST['order_id'])
    : '';
$callbackStatus = isset($_POST['status']) && is_string($_POST['status'])
    ? strtoupper(trim($_POST['status']))
    : '';

if (!preg_match('/^WHMCS_(\d+)_\d+$/', $orderId, $matches)) {
    logTransaction($gatewayParams['name'], ['order_id' => $orderId], 'Invalid order ID');
    http_response_code(400);
    exit('Invalid order');
}

$invoiceId = (int) $matches[1];
checkCbInvoiceID($invoiceId, $gatewayParams['name']);

if (!in_array($callbackStatus, ['SUCCESS', 'COMPLETED'], true)) {
    logTransaction($gatewayParams['name'], ['order_id' => $orderId], 'Callback not successful; no payment applied');
    echo 'IGNORED';
    exit;
}

$userToken = isset($gatewayParams['user_token']) ? trim((string) $gatewayParams['user_token']) : '';
if ($userToken === '' || !function_exists('curl_init')) {
    logTransaction($gatewayParams['name'], ['order_id' => $orderId], 'Payment verification is not configured');
    http_response_code(503);
    exit('Payment verification unavailable');
}

// The public callback is only a notification. Verify the transaction directly
// with the payment provider before crediting the WHMCS invoice.
$curl = curl_init('https://pay.x-server.in/api/check-order-status');
if ($curl === false) {
    http_response_code(503);
    exit('Payment verification unavailable');
}

curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'user_token' => $userToken,
        'order_id' => $orderId,
    ]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);

$responseBody = curl_exec($curl);
$curlError = curl_errno($curl);
$httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

if ($curlError || $httpCode < 200 || $httpCode >= 300 || !is_string($responseBody)) {
    logTransaction($gatewayParams['name'], ['order_id' => $orderId], 'Provider status check failed');
    http_response_code(503);
    exit('Payment verification unavailable');
}

$providerResponse = json_decode($responseBody, true);
$result = is_array($providerResponse) && isset($providerResponse['result']) && is_array($providerResponse['result'])
    ? $providerResponse['result']
    : [];

$verifiedOrderId = isset($result['orderId']) ? (string) $result['orderId'] : '';
$verifiedStatus = strtoupper((string) ($result['status'] ?? $result['txnStatus'] ?? ''));

if ($verifiedOrderId !== $orderId) {
    logTransaction($gatewayParams['name'], ['order_id' => $orderId], 'Provider order ID mismatch');
    http_response_code(409);
    exit('Payment verification failed');
}

if (!in_array($verifiedStatus, ['SUCCESS', 'COMPLETED'], true)) {
    logTransaction($gatewayParams['name'], ['order_id' => $orderId], 'Provider has not confirmed payment');
    echo 'IGNORED';
    exit;
}

$verifiedAmount = $result['amount'] ?? null;
$invoiceAmount = Capsule::table('tblinvoices')->where('id', $invoiceId)->value('total');

if (!is_numeric($verifiedAmount) || !is_numeric($invoiceAmount)
    || number_format((float) $verifiedAmount, 2, '.', '') !== number_format((float) $invoiceAmount, 2, '.', '')) {
    logTransaction($gatewayParams['name'], ['order_id' => $orderId], 'Verified amount does not match invoice');
    http_response_code(409);
    exit('Payment amount mismatch');
}

checkCbTransID($orderId);
addInvoicePayment($invoiceId, $orderId, $verifiedAmount, 0, $gatewayModuleName);
logTransaction($gatewayParams['name'], [
    'order_id' => $orderId,
    'verified_status' => $verifiedStatus,
    'verified_amount' => $verifiedAmount,
], 'Successful - server-side verified');
echo 'OK';
