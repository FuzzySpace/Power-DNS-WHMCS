{* DNSSEC management tab *}
<h4 style="margin-top:18px;">DNSSEC Signing</h4>

<div class="row">
  <div class="col-sm-6">
    <div class="panel panel-{if $dnssecEnabled}success{else}default{/if}">
      <div class="panel-body">
        <div style="display:flex;align-items:center;justify-content:space-between;">
          <div>
            <strong>DNSSEC Status:</strong>
            <span class="label label-{if $dnssecEnabled}success{else}default{/if}" style="margin-left:8px;">
              {if $dnssecEnabled}Enabled{else}Disabled{/if}
            </span>
            <p class="text-muted" style="font-size:12px;margin:6px 0 0;">
              {if $dnssecEnabled}
                Your zone is signed. Add the DS records below to your domain registrar.
              {else}
                Enable DNSSEC to authenticate your DNS records with cryptographic signatures.
              {/if}
            </p>
          </div>
          <div>
            {if $licensed}
            <form method="post" action="" id="pdns-dnssec-form">
              {csrf_token}
              <input type="hidden" name="pdns_action" value="toggle_dnssec">
              <input type="hidden" name="pdns_p_ajax"  value="1">
              <input type="hidden" name="service_id"   value="{$serviceId|escape}">
              <input type="hidden" name="dnssec_enable" value="{if $dnssecEnabled}0{else}1{/if}">
              <button type="submit" class="btn btn-{if $dnssecEnabled}danger{else}success{/if}" id="pdns-dnssec-btn">
                <i class="fa fa-{if $dnssecEnabled}times{else}check{/if}"></i>
                {if $dnssecEnabled}Disable DNSSEC{else}Enable DNSSEC{/if}
              </button>
              <span class="pdns-spinner" id="pdns-dnssec-spinner"><i class="fa fa-spinner fa-spin"></i></span>
            </form>
            {else}
            <button class="btn btn-default" disabled>License required</button>
            {/if}
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

{if $dnssecEnabled && $dsRecords}
<h4>DS Records <small class="text-muted">– submit these to your domain registrar</small></h4>
<div class="table-responsive">
  <table class="table table-bordered pdns-ds-table">
    <thead>
      <tr>
        <th>Key Tag</th><th>Algorithm</th><th>Digest Type</th><th>Digest</th><th>Key Type</th>
      </tr>
    </thead>
    <tbody>
      {foreach from=$dsRecords item=ds}
      <tr>
        <td>{$ds.keytag|escape}</td>
        <td>{$ds.algorithm|escape}</td>
        <td>{$ds.digesttype|escape}</td>
        <td style="word-break:break-all;">{$ds.digest|escape}</td>
        <td><span class="label label-info">{$ds.keytype|escape}</span></td>
      </tr>
      {/foreach}
    </tbody>
  </table>
</div>
<div class="well well-sm" style="font-size:12px;">
  <i class="fa fa-info-circle"></i>
  Copy the DS record(s) above and add them to your domain registrar's DNSSEC settings
  (sometimes labelled "DS records" or "Delegation Signer"). The format required varies
  by registrar, but typically requires Key Tag, Algorithm, Digest Type, and Digest.
</div>
{elseif $dnssecEnabled}
<div class="alert alert-info">
  <i class="fa fa-spinner fa-spin"></i> DS records are being generated. Refresh the page in a moment.
</div>
{/if}

<script>
(function() {
  var form    = document.getElementById('pdns-dnssec-form');
  var spinner = document.getElementById('pdns-dnssec-spinner');
  var btn     = document.getElementById('pdns-dnssec-btn');
  if (form && window.fetch) {
    form.addEventListener('submit', function(e) {
      e.preventDefault();
      if (!confirm(btn.textContent.trim() + ' for zone {$zone|escape}?')) return;
      btn.disabled = true;
      spinner.style.display = '';
      var fd = new FormData(form);
      fetch(window.location.href, { method:'POST', body:fd, credentials:'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
          btn.disabled = false; spinner.style.display = 'none';
          if (data.success) { window.location.reload(); }
          else { pdnsPremiumAlert('danger', data.error || 'Unknown error.'); }
        })
        .catch(function(err) {
          btn.disabled = false; spinner.style.display = 'none';
          pdnsPremiumAlert('danger', 'Request failed: ' + err.message);
        });
    });
  }
}());
</script>
