<?php
require_once 'includes/application_top.php';
require_once __DIR__ . '/includes/modules/payment/coinbase/init.php';
require_once __DIR__ . '/includes/modules/payment/coinbase/const.php';

function updateOrderStatus($orderId, $newOrderStatus, $comments)
{
    $sql_data_array = array(
        'orders_id' => $orderId,
        'orders_status_id' => $newOrderStatus,
        'date_added' => 'now()',
        'comments' => $comments,
        'customer_notified' => 0
    );
    tep_db_perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);
    tep_db_query("UPDATE " . TABLE_ORDERS . "
                  SET `orders_status` = '" . (int)$newOrderStatus . "'
                  WHERE `orders_id` = '" . (int)$orderId . "'");
}

$debug_email = '';

function sendDebugEmail($message = '', $http_error = false)
{
    global $debug_email;
    if (!empty($debug_email)) {
        $str = "Coinbase IPN Debug Report\n\n";
        if (!empty($message)) {
            $str .= "Debug/Error Message: " . $message . "\n\n";
        }

        $str .= "POST Vars\n\n";
        foreach ($_POST as $k => $v) {
            $str .= "$k => $v \n";
        }
        $str .= "\nGET Vars\n\n";
        foreach ($_GET as $k => $v) {
            $str .= "$k => $v \n";
        }
        $html_msg['EMAIL_MESSAGE_HTML'] = nl2br($str);

        @mail($debug_email, 'Coinbase IPN', $str);
    }
    if ($http_error) {
        header("500 Internal Server Error");
    }
    die("[IPN Error]: " . $message . "\n");
}

$settings = tep_db_query("SELECT configuration_key,configuration_value FROM " . TABLE_CONFIGURATION
    . " WHERE configuration_key LIKE 'MODULE\_PAYMENT\_COINBASE\_%'");

if (tep_db_num_rows($settings) === 0) {
    sendDebugEmail('Settings not found.');
}

while ($setting = tep_db_fetch_array($settings)) {
    switch ($setting['configuration_key']) {
        case 'MODULE_PAYMENT_COINBASE_SHARED_SECRET':
            $sharedSecret = $setting['configuration_value'];
            break;
        case 'MODULE_PAYMENT_COINBASE_PENDING_STATUS_ID':
            $pendingStatusId = $setting['configuration_value'];
            break;
        case 'MODULE_PAYMENT_COINBASE_PROCESSING_STATUS_ID':
            $processingStatusId = $setting['configuration_value'];
            break;
    }
}

if (empty($sharedSecret)) {
    sendDebugEmail('shared secret secret not set in admin panel.');
}

$headers = array_change_key_case(getallheaders());
$signatureHeader = isset($headers[SIGNATURE_HEADER]) ? $headers[SIGNATURE_HEADER] : null;
$payload = trim(file_get_contents('php://input'));

try {
    $event = \Coinbase\Webhook::buildEvent($payload, $signatureHeader, $sharedSecret);
} catch (\Exception $exception) {
    sendDebugEmail($exception->getMessage());
}

$charge = $event->data;

if ($charge->getMetadataParam(METADATA_SOURCE_PARAM) != METADATA_SOURCE_VALUE) {
    sendDebugEmail('not whmcs charge');
}

if (($orderId = $charge->getMetadataParam('invoiceid')) === null
    || ($customerId = $charge->getMetadataParam('clientid')) === null) {
    sendDebugEmail('[Error] invoice id is not found in charge');
}

$query = "SELECT * FROM " . TABLE_ORDERS . " WHERE `orders_id`='" . tep_db_input($orderId) . "' AND `customers_id`='" . tep_db_input($customerId) . "'  ORDER BY `orders_id` DESC";
$order = tep_db_query($query);

if (tep_db_num_rows($query) === 0) {
    sendDebugEmail('order is not exists');
}

$order = tep_db_fetch_array($order);
$total = $order['order_total'];
$currency = $order['currency'];

switch ($event->type) {
    case 'charge:created':
        updateOrderStatus($orderId, $pendingStatusId, sprintf('Charge was created. Charge Id: %s', $charge->id));
        break;
    case 'charge:failed':
        updateOrderStatus($orderId, $pendingStatusId, sprintf('Charge was failed. Charge Id: %s', $charge->id));
        break;
    case 'charge:delayed':
        updateOrderStatus($orderId, $pendingStatusId, sprintf('Charge was delayed. Charge Id: %s', $charge->id));
        break;
    case 'charge:confirmed':
        $transactionId = '';
        $total = '';
        $currency = '';

        foreach ($charge->payments as $payment) {
            if (strtolower($payment['status']) === 'confirmed') {
                $transactionId = $payment['transaction_id'];
                $total = isset($payment['value']['local']['amount']) ? $payment['value']['local']['amount'] : $total;
                $currency = isset($payment['value']['local']['currency']) ? $payment['value']['local']['currency'] : $currency;
            }
        }

        updateOrderStatus($orderId, $processingStatusId, sprintf('Charge was confirmed. Charge Id: %s. Received %s %S. Transaction id %s.', $charge->id, $total, $currency, $transactionId));
        break;
}
?>
