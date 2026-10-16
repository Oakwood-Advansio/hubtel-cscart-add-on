<div class="control-group">
    <label class="control-label cm-required" for="hubtel_client_id">{__("hubtel_client_id")}:</label>
    <div class="controls">
        <input type="text" name="payment_data[processor_params][client_id]" id="hubtel_client_id" value="{$processor_params.client_id}" class="input-text" size="60" />
        <p class="muted description">{__("hubtel_client_id_description")}</p>
    </div>
</div>

<div class="control-group">
    <label class="control-label cm-required" for="hubtel_client_secret">{__("hubtel_client_secret")}:</label>
    <div class="controls">
        <input type="password" name="payment_data[processor_params][client_secret]" id="hubtel_client_secret" value="{$processor_params.client_secret}" class="input-text" size="60" autocomplete="off" />
        <p class="muted description">{__("hubtel_client_secret_description")}</p>
    </div>
</div>

<div class="control-group">
    <label class="control-label cm-required" for="hubtel_merchant_account_number">{__("hubtel_merchant_account_number")}:</label>
    <div class="controls">
        <input type="text" name="payment_data[processor_params][merchant_account_number]" id="hubtel_merchant_account_number" value="{$processor_params.merchant_account_number}" class="input-text" size="30" />
        <p class="muted description">{__("hubtel_merchant_account_number_description")}</p>
    </div>
</div>

<div class="control-group">
    <label class="control-label cm-required" for="hubtel_cron_key">{__("hubtel_cron_key")}:</label>
    <div class="controls">
        <input type="text" name="payment_data[processor_params][cron_key]" id="hubtel_cron_key" value="{$processor_params.cron_key}" class="input-text" size="40" />
        <p class="muted description">{__("hubtel_cron_key_description")}</p>
        {if $processor_params.cron_key}
            <p class="muted description">{__("hubtel_cron_url")}: <code>{fn_url("hubtel.check_pending&key=`$processor_params.cron_key`", "C", "https")}</code></p>
        {/if}
    </div>
</div>

