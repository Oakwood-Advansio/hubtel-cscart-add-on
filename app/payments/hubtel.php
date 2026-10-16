<?php

use Tygh\Registry;

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

if (defined('PAYMENT_NOTIFICATION')) {
    // Called by Hubtel (callback) or by the customer browser (return, cancel)
    $order_id = isset($_REQUEST['order_id']) ? (int) $_REQUEST['order_id'] : 0;

    if (empty($order_id)) {
        exit;
    }

    if ($mode == 'callback') {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true);
        $body = is_array($body) ? $body : array();

        $data = array();
        if (isset($body['Data']) && is_array($body['Data'])) {
            $data = $body['Data'];
        } elseif (isset($body['data']) && is_array($body['data'])) {
            $data = $body['data'];
        }

        $reference = '';
        if (isset($data['ClientReference'])) {
            $reference = (string) $data['ClientReference'];
        } elseif (isset($data['clientReference'])) {
            $reference = (string) $data['clientReference'];
        } elseif (isset($body['ClientReference'])) {
            $reference = (string) $body['ClientReference'];
        } elseif (isset($body['clientReference'])) {
            $reference = (string) $body['clientReference'];
        }

        $code = '';
        if (isset($body['ResponseCode'])) {
            $code = (string) $body['ResponseCode'];
        } elseif (isset($body['responseCode'])) {
            $code = (string) $body['responseCode'];
        }

        // Never trust the callback alone. Process_order checks the status with Hubtel.
        if ($reference !== '') {
            fn_hubtel_process_order($order_id, $reference, $code);
        }

        header('HTTP/1.1 200 OK');
        header('Content-Type: text/plain');
        echo 'OK';
        exit;
    }

    if ($mode == 'return') {
        $order_info = fn_get_order_info($order_id);
        $reference = isset($order_info['payment_info']['hubtel_client_reference'])
            ? $order_info['payment_info']['hubtel_client_reference']
            : '';

        if ($reference !== '') {
            $result = fn_hubtel_process_order($order_id, $reference);

            if ($result == 'pending' || $result == 'mismatch') {
                // Keep the order open. The callback or the cron check will confirm it.
                fn_finish_payment($order_id, array(
                    'order_status' => 'O',
                    'reason_text' => __('hubtel_waiting_for_confirmation'),
                ));
            }
        }

        fn_order_placement_routines('route', $order_id);
    }

    if ($mode == 'cancel') {
        $order_info = fn_get_order_info($order_id);

        if (!empty($order_info) && $order_info['status'] !== 'P') {
            fn_finish_payment($order_id, array(
                'order_status' => 'F',
                'reason_text' => __('hubtel_customer_cancelled'),
            ));
        }

        fn_order_placement_routines('route', $order_id);
    }

    exit;
}

// Start of payment: runs when the customer places the order
$params = isset($processor_data['processor_params']) && is_array($processor_data['processor_params'])
    ? $processor_data['processor_params']
    : array();
$order_id = (int) $order_id;

if (empty($params['client_id']) || empty($params['client_secret']) || empty($params['merchant_account_number'])) {
    fn_set_notification('E', __('error'), __('hubtel_credentials_missing'));
    fn_finish_payment($order_id, array(
        'order_status' => 'F',
        'reason_text' => __('hubtel_credentials_missing'),
    ));
    fn_order_placement_routines('route', $order_id);
    exit;
}

$amount = fn_hubtel_to_ghs($order_info['total']);

if ($amount === false || $amount <= 0) {
    fn_set_notification('E', __('error'), __('hubtel_ghs_currency_required'));
    fn_finish_payment($order_id, array(
        'order_status' => 'F',
        'reason_text' => __('hubtel_ghs_currency_required'),
    ));
    fn_order_placement_routines('route', $order_id);
    exit;
}

// Unique reference, max 32 characters. Format: o<order_id>x<random>
$client_reference = 'o' . $order_id . 'x' . substr(md5(uniqid('', true)), 0, 12);

$description = trim(preg_replace('/[^A-Za-z0-9 ]/', '', 'Order ' . $order_id));

$payload = array(
    'totalAmount' => $amount,
    'description' => $description,
    'callbackUrl' => fn_url('payment_notification.callback?payment=hubtel&order_id=' . $order_id, AREA, 'https'),
    'returnUrl' => fn_url('payment_notification.return?payment=hubtel&order_id=' . $order_id, AREA, 'https'),
    'cancellationUrl' => fn_url('payment_notification.cancel?payment=hubtel&order_id=' . $order_id, AREA, 'https'),
    'merchantAccountNumber' => trim($params['merchant_account_number']),
    'clientReference' => $client_reference,
);

if (!empty($order_info['firstname']) || !empty($order_info['lastname'])) {
    $payload['payeeName'] = trim($order_info['firstname'] . ' ' . $order_info['lastname']);
}
if (!empty($order_info['phone'])) {
    $payload['payeeMobileNumber'] = $order_info['phone'];
}
if (!empty($order_info['email'])) {
    $payload['payeeEmail'] = $order_info['email'];
}

$result = fn_hubtel_initiate_checkout($params, $payload);

$res_code = isset($result['responseCode']) ? (string) $result['responseCode'] : (isset($result['code']) ? (string) $result['code'] : (isset($result['ResponseCode']) ? (string) $result['ResponseCode'] : ''));
if ($res_code !== '' && ctype_digit($res_code) && strlen($res_code) < 4) {
    $res_code = str_pad($res_code, 4, '0', STR_PAD_LEFT);
}

$checkout_data = (isset($result['data']) && is_array($result['data']))
    ? $result['data']
    : ((isset($result['Data']) && is_array($result['Data'])) ? $result['Data'] : $result);
$checkout_url = isset($checkout_data['checkoutUrl'])
    ? (string) $checkout_data['checkoutUrl']
    : (isset($checkout_data['CheckoutUrl'])
        ? (string) $checkout_data['CheckoutUrl']
        : (isset($checkout_data['checkout_url']) ? (string) $checkout_data['checkout_url'] : ''));

if ($checkout_url === '' || ($res_code !== '' && $res_code !== '0000')) {
    $reason = $res_code !== '' ? fn_hubtel_response_message($res_code) : __('hubtel_initiate_failed');
    fn_hubtel_log('Initiate failed for order ' . $order_id . ': ' . json_encode($result));
    fn_set_notification('E', __('error'), $reason);

    fn_finish_payment($order_id, array(
        'order_status' => 'F',
        'reason_text' => $reason,
    ));
    fn_order_placement_routines('route', $order_id);
    exit;
}

$checkout_id = isset($checkout_data['checkoutId']) ? (string) $checkout_data['checkoutId'] : (isset($checkout_data['CheckoutId']) ? (string) $checkout_data['CheckoutId'] : '');
$checkout_token = bin2hex(random_bytes(32));

fn_update_order_payment_info($order_id, array(
    'hubtel_client_reference' => $client_reference,
    'hubtel_checkout_id' => $checkout_id,
    'hubtel_checkout_url' => $checkout_url,
    'hubtel_checkout_token' => $checkout_token,
));

$checkout_page_url = fn_url(
    'hubtel.checkout?order_id=' . $order_id . '&token=' . rawurlencode($checkout_token),
    'C',
    'https'
);

if (defined('AJAX_REQUEST')) {
    \Tygh::$app['ajax']->assign('force_redirection', $checkout_page_url);
    exit;
}

fn_create_payment_form($checkout_page_url, array(), 'Hubtel', false, 'get');
exit;
