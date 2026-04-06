{* PowerDNS – Service Overview Page
   Shown by default when a client visits their DNS hosting service.
   From here they navigate to the full DNS manager.
*}

<div class="pdns-overview">

  {if $error}
    <div class="alert alert-warning">
      <i class="fa fa-exclamation-triangle"></i> {$error|escape}
    </div>
  {/if}

  {* ── Zone header ─────────────────────────────────────────────────────── *}
  <div class="pdns-overview-header">
    <div class="pdns-overview-zone">
      <i class="fa fa-globe fa-2x pdns-zone-icon"></i>
      <div>
        <h3 class="pdns-zone-title">{$zone|escape}</h3>
        <span class="label label-success"><i class="fa fa-check-circle"></i> Active</span>
        &nbsp;
        <span class="text-muted"><i class="fa fa-list-ul"></i> {$recordCount} record{if $recordCount != 1}s{/if}</span>
      </div>
    </div>
    <a href="{$manageUrl|escape}" class="btn btn-primary btn-lg pdns-manage-btn">
      <i class="fa fa-pencil-square-o"></i>&nbsp; Manage DNS Records
    </a>
  </div>

  {* ── Nameservers ─────────────────────────────────────────────────────── *}
  <div class="panel panel-info">
    <div class="panel-heading">
      <h4 class="panel-title"><i class="fa fa-server"></i> Point your domain to these nameservers</h4>
    </div>
    <div class="panel-body">
      <p class="text-muted" style="margin-bottom:14px;">
        Log in to your domain registrar and set your domain's nameservers to the values below.
        DNS changes can take up to 24&nbsp;hours to propagate globally.
      </p>
      {foreach from=$nameservers item=ns name=nsloop}
        <div class="pdns-ns-row">
          <span class="pdns-ns-label">NS {$nsloop.iteration}</span>
          <code id="pdns-ns-{$nsloop.index}">{$ns|escape}</code>
          <button type="button" class="btn btn-xs btn-default pdns-copy-btn"
                  data-target="pdns-ns-{$nsloop.index}">
            <i class="fa fa-copy"></i> Copy
          </button>
        </div>
      {/foreach}
    </div>
  </div>

  {* ── Quick-action cards ──────────────────────────────────────────────── *}
  <div class="row pdns-card-row">
    <div class="col-sm-4">
      <div class="panel panel-default pdns-card">
        <div class="panel-body text-center">
          <i class="fa fa-list fa-3x text-primary" style="margin-bottom:10px;"></i>
          <h4>{$recordCount}</h4>
          <p class="text-muted">Active Records</p>
          <a href="{$manageUrl|escape}" class="btn btn-primary btn-block">
            <i class="fa fa-pencil-square-o"></i> Manage Records
          </a>
        </div>
      </div>
    </div>
    <div class="col-sm-4">
      <div class="panel panel-default pdns-card">
        <div class="panel-body text-center">
          <i class="fa fa-envelope fa-3x text-warning" style="margin-bottom:10px;"></i>
          <h4>Email Setup</h4>
          <p class="text-muted">Add MX &amp; SPF records</p>
          <a href="{$manageUrl|escape}" class="btn btn-default btn-block">
            <i class="fa fa-arrow-right"></i> Go to Records
          </a>
        </div>
      </div>
    </div>
    <div class="col-sm-4">
      <div class="panel panel-default pdns-card">
        <div class="panel-body text-center">
          <i class="fa fa-globe fa-3x text-info" style="margin-bottom:10px;"></i>
          <h4>Web Hosting</h4>
          <p class="text-muted">Add A &amp; CNAME records</p>
          <a href="{$manageUrl|escape}" class="btn btn-default btn-block">
            <i class="fa fa-arrow-right"></i> Go to Records
          </a>
        </div>
      </div>
    </div>
  </div>

</div>{* /pdns-overview *}

<style>
  .pdns-overview-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 24px;
    padding: 20px;
    background: #f8f9fa;
    border: 1px solid #ddd;
    border-radius: 6px;
  }
  .pdns-overview-zone  { display:flex; align-items:center; gap:16px; }
  .pdns-zone-icon      { color:#337ab7; }
  .pdns-zone-title     { margin:0 0 6px; font-size:22px; }
  .pdns-manage-btn     { white-space:nowrap; }
  .pdns-ns-row         { display:flex; align-items:center; gap:10px; margin-bottom:8px; }
  .pdns-ns-row:last-child { margin-bottom:0; }
  .pdns-ns-label       { display:inline-block; width:32px; font-weight:700; color:#666; font-size:12px; }
  .pdns-ns-row code    { font-size:14px; flex:1; }
  .pdns-card           { border-top: 3px solid #337ab7; }
  .pdns-card-row       { margin-top:8px; }
</style>

<script>
(function () {
  document.querySelectorAll('.pdns-copy-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var id   = btn.getAttribute('data-target');
      var text = document.getElementById(id) ? document.getElementById(id).innerText : '';
      if (navigator.clipboard && text) {
        navigator.clipboard.writeText(text).then(function () {
          btn.innerHTML = '<i class="fa fa-check"></i> Copied!';
          setTimeout(function () { btn.innerHTML = '<i class="fa fa-copy"></i> Copy'; }, 2000);
        });
      }
    });
  });
}());
</script>
