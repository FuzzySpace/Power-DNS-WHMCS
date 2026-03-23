{* Shown when the service is not yet active (pending, suspended, cancelled, etc.) *}
<div class="alert alert-warning">
  <i class="fa fa-lock"></i>
  <strong>DNS Manager Unavailable</strong><br>
  Your DNS service is currently <strong>{$status|escape}</strong>.
  {if $status == 'Pending'}
    Your order is being processed. Once payment is confirmed and the service is activated you will be able to manage your DNS records here.
  {elseif $status == 'Suspended'}
    Your service has been suspended. Please contact support or settle any outstanding invoices to restore access.
  {else}
    Please contact support if you believe this is an error.
  {/if}
</div>
