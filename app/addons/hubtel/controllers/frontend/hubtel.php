<?php

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

$order_id = isset($_REQUEST['order_id']) ? (int) $_REQUEST['order_id'] : 0;
$token = isset($_REQUEST['token']) ? (string) $_REQUEST['token'] : '';

// Page that shows the Hubtel payment page inside an iframe on the store
if ($mode == 'checkout') {
    $order_info = fn_hubtel_get_order_by_token($order_id, $token);
    $checkout_url = isset($order_info['payment_info']['hubtel_checkout_url'])
        ? (string) $order_info['payment_info']['hubtel_checkout_url']
        : '';

    if (empty($order_info)) {
        header('HTTP/1.1 403 Forbidden');
        exit;
    }

    if ($order_info['status'] === 'P') {
        fn_redirect(fn_hubtel_finish_url($order_id, $token));
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

    $query = 'order_id=' . $order_id . '&token=' . rawurlencode($token);

    Tygh::$app['view']->assign('page_title', __('hubtel_checkout_title'));
    Tygh::$app['view']->assign('hubtel_checkout_url', $checkout_url);
    Tygh::$app['view']->assign('hubtel_order_id', $order_id);
    Tygh::$app['view']->assign('hubtel_order_total', $order_info['total']);
    Tygh::$app['view']->assign('hubtel_status_url', fn_url('hubtel.status?' . $query, 'C', 'https'));
    Tygh::$app['view']->assign('hubtel_finish_url', fn_hubtel_finish_url($order_id, $token));
    Tygh::$app['view']->assign('hubtel_cancel_url', fn_url('payment_notification.cancel?payment=hubtel&' . $query, 'C', 'https'));

    return array(CONTROLLER_STATUS_OK);
}

// Polled by the iframe page every few seconds. Returns JSON.
if ($mode == 'status') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');

    $order_info = fn_hubtel_get_order_by_token($order_id, $token);

    if (empty($order_info)) {
        header('HTTP/1.1 403 Forbidden');
        echo json_encode(array('state' => 'error'));
        exit;
    }

    $state = 'pending';

    if ($order_info['status'] === 'P') {
        $state = 'paid';
    } elseif ($order_info['status'] === 'F') {
        $state = 'failed';
    } else {
        $reference = isset($order_info['payment_info']['hubtel_client_reference'])
            ? (string) $order_info['payment_info']['hubtel_client_reference']
            : '';

        if ($reference !== '') {
            $result = fn_hubtel_process_order($order_id, $reference);
            if ($result === 'paid' || $result === 'failed') {
                $state = $result;
            }
        }
    }

    echo json_encode(array('state' => $state));
    exit;
}

// Top-level page the customer lands on after paying or cancelling
if ($mode == 'finish') {
    $order_info = fn_hubtel_get_order_by_token($order_id, $token);

    if (empty($order_info)) {
        header('HTTP/1.1 403 Forbidden');
        exit;
    }

    fn_order_placement_routines('route', $order_id);
    exit;
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
