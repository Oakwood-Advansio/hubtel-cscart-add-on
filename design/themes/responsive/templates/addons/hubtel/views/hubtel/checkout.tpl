<style>
    .hubtel-checkout {
        min-height: 100vh;
    }

    .hubtel-checkout__frame {
        border: 0;
        display: block;
        height: 100vh;
        width: 100%;
    }
</style>
<main class="hubtel-checkout">
    <iframe
        class="hubtel-checkout__frame"
        src="{$hubtel_checkout_url|escape:"html"}"
        title="{__("hubtel_checkout_title")}"
        allow="payment *"
        allowpaymentrequest
        allowfullscreen
    ></iframe>
</main>
