<?php

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

if ($mode == 'checkout') {
    $order_id = isset($_REQUEST['order_id']) ? (int) $_REQUEST['order_id'] : 0;
    $token = isset($_REQUEST['token']) ? (string) $_REQUEST['token'] : '';
    $order_info = $order_id ? fn_get_order_info($order_id) : array();
    $payment_info = isset($order_info['payment_info']) && is_array($order_info['payment_info'])
        ? $order_info['payment_info']
        : array();
    $saved_token = isset($payment_info['hubtel_checkout_token'])
        ? (string) $payment_info['hubtel_checkout_token']
        : '';
    $checkout_url = isset($payment_info['hubtel_checkout_url'])
        ? (string) $payment_info['hubtel_checkout_url']
        : '';
    $processor_data = !empty($order_info['payment_id'])
        ? fn_get_processor_data($order_info['payment_id'])
        : array();

    if (
        empty($order_info)
        || empty($processor_data['processor_script'])
        || $processor_data['processor_script'] !== 'hubtel.php'
        || $saved_token === ''
        || $token === ''
        || !hash_equals($saved_token, $token)
    ) {
        header('HTTP/1.1 403 Forbidden');
        exit;
    }

    $checkout_url_parts = parse_url($checkout_url);
    if (
        empty($checkout_url_parts['scheme'])
        || strtolower($checkout_url_parts['scheme']) !== 'https'
        || empty($checkout_url_parts['host'])
    ) {
        fn_hubtel_log('Invalid checkout URL for order ' . $order_id);
        header('HTTP/1.1 502 Bad Gateway');
        exit;
    }

    Tygh::$app['view']->assign('page_title', __('hubtel_checkout_title'));
    Tygh::$app['view']->assign('hubtel_checkout_url', $checkout_url);
    Tygh::$app['view']->assign('hubtel_order_id', $order_id);
    return array(CONTROLLER_STATUS_OK);
}

// Cron: index.php?dispatch=hubtel.check_pending&key=YOUR_CRON_KEY
if ($mode == 'check_pending') {
    $key = isset($_REQUEST['key']) ? (string) $_REQUEST['key'] : '';

    $keys = db_get_fields(
        'SELECT p.payment_id FROM ?:payments AS p'
        . ' INNER JOIN ?:payment_processors AS pp ON pp.processor_id = p.processor_id'
        . ' WHERE pp.processor_script = ?s',
        'hubtel.php'
    );

    $allowed = false;
    foreach ($keys as $payment_id) {
        $processor_data = fn_get_processor_data($payment_id);
        $saved = isset($processor_data['processor_params']['cron_key']) ? (string) $processor_data['processor_params']['cron_key'] : '';

        if ($saved !== '' && hash_equals($saved, $key)) {
            $allowed = true;
            break;
        }
    }

    if (!$allowed) {
        header('HTTP/1.1 403 Forbidden');
        echo 'Forbidden';
        exit;
    }

    $summary = fn_hubtel_check_pending_orders();

    header('Content-Type: application/json');
    echo json_encode($summary);
    exit;
}

return array(CONTROLLER_STATUS_NO_PAGE);
