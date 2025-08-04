<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

// 1. Module Configuration
function puffxpay_config()
{
    $systemUrl = rtrim($GLOBALS['CONFIG']['SystemURL'], '/');
    $webhookUrl = $systemUrl . '/modules/gateways/callback/puffxpay.php';

    $webhookHtml = <<<HTML
<div style="margin-top:8px;">
    <label><strong>Webhook URL:</strong></label>
    <div style="display:flex;gap:8px;margin-top:5px;">
        <input type="text" id="webhookUrl" value="$webhookUrl" readonly style="flex:1;padding:6px;border:1px solid #ccc;border-radius:4px;" />
        <button type="button" onclick="copyWebhook()" style="padding:6px 12px;background:#007bff;color:#fff;border:none;border-radius:4px;cursor:pointer;">Copy</button>
    </div>
    <div id="copyMsg" style="color:green;font-size:13px;margin-top:4px;display:none;">✅ Copied!</div>
    <small>Paste this URL in your payment gateway dashboard webhook settings.</small>
    <script>
        function copyWebhook() {
            var input = document.getElementById("webhookUrl");
            input.select();
            navigator.clipboard.writeText(input.value).then(function () {
                var msg = document.getElementById("copyMsg");
                msg.style.display = "block";
                setTimeout(() => { msg.style.display = "none"; }, 2000);
            });
        }
    </script>
</div>
HTML;

    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'Puffx Pay',
        ],
        'user_token' => [
            'FriendlyName' => 'API Key (User Token)',
            'Type' => 'text',
            'Size' => '64',
        ],
        'route' => [
            'FriendlyName' => 'Route (1 = UPI, 2 = Wallet, 3 = NetBanking)',
            'Type' => 'dropdown',
            'Options' => '1,2,3',
            'Default' => '1',
        ],
        'webhook_info' => [
            'FriendlyName' => 'Webhook URL',
            'Type' => 'text',
            'Default' => '',
            'Description' => $webhookHtml,
        ],
    ];
}

// 2. Module Metadata (shows in WHMCS admin)
function puffxpay_meta()
{
    return [
        'DisplayName' => 'Puffx Pay',
        'APIVersion' => '1.1', // Required for callback support
        'GatewayType' => 'Invoice',
        'Description' => 'Puffx Pay gateway integration for WHMCS. Accept payments via UPI, Wallet, and NetBanking.',
        'SupportEmail' => 'support@puffxpay.site',
        'Author' => 'Puffx Host Team',
        'CompanyName' => 'Puffx Host',
        'Website' => 'https://puffxpay.site',
    ];
}

// 3. Create Payment Link
function puffxpay_link($params)
{
    $invoiceId = $params['invoiceid'];
    $amount = $params['amount'];
    $systemurl = rtrim($params['systemurl'], '/');
    $callbackUrl = $systemurl . '/modules/gateways/callback/puffxpay.php';
    $client = $params['clientdetails'];

    $user_token = $params['user_token'];
    $route = $params['route'];

    $order_id = "WHMCS_" . $invoiceId . "_" . time();

    $postfields = [
        'user_token' => $user_token,
        'amount' => $amount,
        'order_id' => $order_id,
        'redirect_url' => $systemurl . "/viewinvoice.php?id=" . $invoiceId,
        'webhook_url' => $callbackUrl,
        'customer_mobile' => $client['phonenumber'],
        'remark1' => "Invoice: $invoiceId",
        'remark2' => $client['email'],
        'route' => $route
    ];

    $ch = curl_init("https://pay.x-server.in/api/create-order");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postfields));
    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);

    // Optional: Log for debugging
    // file_put_contents(__DIR__ . '/debug_log.txt', date('Y-m-d H:i:s') . " => " . print_r($result, true) . "\n", FILE_APPEND);

    if (isset($result['status']) && $result['status'] === true && isset($result['result']['payment_url'])) {
        $payment_url = $result['result']['payment_url'];
        return "<div style='text-align:center;margin-top:20px;'>
                    <a href='$payment_url' target='_blank' style='
                       display:block;
        background-color:#d6e4ff;
        color:#1062fe;
        padding:14px 0;
        border-radius:3px;
        font-size:15px;
        font-weight:500;
        width:100%;
        max-width:100%;
        text-align:center;
        text-decoration:none;
        margin:20px auto;
        transition:background-color 0.3s ease;
    ' onmouseover=\"this.style.backgroundColor='#d6e4ff'\"
       onmouseout=\"this.style.backgroundColor='#d6e4ff'\">
        Pay with QR UPI
    </a>
                </div>";
    } else {
        return "<div style='background:#ffe6e6;padding:15px;border-radius:6px;color:#b30000;font-weight:600;text-align:center;margin-top:20px;'>
                    ❌ Unable to create payment link. Please contact support.
                </div>";
    }
}
