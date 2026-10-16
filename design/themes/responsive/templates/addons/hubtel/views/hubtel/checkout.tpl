<style>
    .hubtel-checkout { max-width: 720px; margin: 0 auto; padding: 10px 0 30px; }
    .hubtel-checkout__head { margin-bottom: 12px; }
    .hubtel-checkout__frame { border: 1px solid #ddd; border-radius: 4px; display: block; height: 680px; width: 100%; background: #fff; }
    .hubtel-checkout__note { margin: 12px 0 0; color: #555; }
    .hubtel-checkout__cancel { display: inline-block; margin-top: 10px; }
    @media (max-width: 480px) { .hubtel-checkout__frame { height: 720px; } }
</style>

<div class="hubtel-checkout" id="hubtel-checkout"
     data-status-url="{$hubtel_status_url|escape:'html'}"
     data-finish-url="{$hubtel_finish_url|escape:'html'}">
    <div class="hubtel-checkout__head">
        <h1 class="ty-mainbox-title">{__("hubtel_checkout_title")}</h1>
        <p>{__("order")} #{$hubtel_order_id}: {include file="common/price.tpl" value=$hubtel_order_total}</p>
    </div>

    <iframe
        class="hubtel-checkout__frame"
        src="{$hubtel_checkout_url|escape:'html'}"
        title="{__("hubtel_checkout_title")}"
        allow="payment *"
        allowpaymentrequest
    ></iframe>

    <p class="hubtel-checkout__note" id="hubtel-checkout-note">{__("hubtel_checkout_waiting")}</p>
    <a class="hubtel-checkout__cancel" href="{$hubtel_cancel_url|escape:'html'}">{__("hubtel_checkout_cancel")}</a>
</div>

<script>
{literal}
(function () {
    var box = document.getElementById('hubtel-checkout');
    var note = document.getElementById('hubtel-checkout-note');
    if (!box) { return; }

    var statusUrl = box.getAttribute('data-status-url');
    var finishUrl = box.getAttribute('data-finish-url');
    var startedAt = new Date().getTime();
    var maxMs = 15 * 60 * 1000;
    var timer = null;
    var busy = false;

    function stop() {
        if (timer) { window.clearInterval(timer); timer = null; }
    }

    function check() {
        if (busy) { return; }

        if (new Date().getTime() - startedAt > maxMs) {
            stop();
            note.innerHTML = box.getAttribute('data-timeout') || note.innerHTML;
            return;
        }

        busy = true;
        var xhr = new XMLHttpRequest();
        xhr.open('GET', statusUrl + '&_=' + new Date().getTime(), true);
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) { return; }
            busy = false;
            if (xhr.status !== 200) { return; }
            try {
                var data = JSON.parse(xhr.responseText);
                if (data && (data.state === 'paid' || data.state === 'failed')) {
                    stop();
                    window.location.href = finishUrl;
                }
            } catch (e) {}
        };
        xhr.send();
    }

    timer = window.setInterval(check, 5000);
})();
{/literal}
</script>
