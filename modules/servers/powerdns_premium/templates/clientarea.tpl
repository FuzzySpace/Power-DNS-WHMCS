{*
  PowerDNS Premium – Client Area (tabbed)
  Tabs: DNS Records | DNSSEC | Propagation | History | Import/Export
*}

{* ── Zone / Nameserver info ─────────────────────────────────────────────── *}
<div class="panel panel-info" style="margin-bottom:16px;">
  <div class="panel-heading">
    <h4 class="panel-title">
      <i class="fa fa-globe"></i> {$zone|escape}
      &nbsp;<span class="label label-success" style="font-size:11px;">Active</span>
    </h4>
  </div>
  <div class="panel-body">
    <p class="text-muted" style="margin-bottom:10px;">
      Point your domain to these nameservers at your registrar.
      DNS changes can take up to 24&nbsp;hours to propagate.
    </p>
    {foreach from=$nameservers item=ns}
      <code style="margin-right:16px;">{$ns|escape}</code>
    {/foreach}
  </div>
</div>

{if !$licensed}
  <div class="alert alert-warning">
    <i class="fa fa-exclamation-triangle"></i>
    <strong>License Notice:</strong> The module license could not be validated.
    Record management is currently disabled. Please contact support.
  </div>
{/if}

{* ── Alert area ─────────────────────────────────────────────────────────── *}
{if $error}
  <div class="alert alert-danger alert-dismissible">
    <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
    <i class="fa fa-exclamation-circle"></i> {$error|escape}
  </div>
{/if}
{if $success}
  <div class="alert alert-success alert-dismissible">
    <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
    <i class="fa fa-check-circle"></i> {$success|escape}
  </div>
{/if}
<div id="pdns-alert" style="display:none;"></div>

{* ── Quota bar ───────────────────────────────────────────────────────────── *}
{if $maxRecords > 0}
  {assign var="pct" value=$recordCount/$maxRecords*100}
  <div class="pdns-quota-bar">
    <small>{$recordCount} / {$maxRecords} records used</small>
    <div class="progress" style="margin-bottom:0;height:6px;">
      <div class="progress-bar{if $pct >= 90} progress-bar-danger{elseif $pct >= 70} progress-bar-warning{else} progress-bar-success{/if}"
           style="width:{if $pct > 100}100{else}{$pct|string_format:"%.0f"}{/if}%;">
      </div>
    </div>
  </div>
{/if}

{* ── Tabs ────────────────────────────────────────────────────────────────── *}
<ul class="nav nav-tabs pdns-tabs" id="pdnsTabs" role="tablist">
  <li class="active"><a href="#tab-records"     data-toggle="tab"><i class="fa fa-list"></i> DNS Records</a></li>
  {if $allowDNSSEC}
  <li>            <a href="#tab-dnssec"      data-toggle="tab"><i class="fa fa-lock"></i> DNSSEC</a></li>
  {/if}
  <li>            <a href="#tab-propagation" data-toggle="tab"><i class="fa fa-globe"></i> Propagation</a></li>
  <li>            <a href="#tab-history"     data-toggle="tab"><i class="fa fa-history"></i> History</a></li>
  <li>            <a href="#tab-io"          data-toggle="tab"><i class="fa fa-exchange"></i> Import / Export</a></li>
</ul>

<div class="tab-content pdns-tab-content">

  {* ═══════════════════════════════════════════════════════════════════════ *}
  {* TAB 1 – DNS Records                                                    *}
  {* ═══════════════════════════════════════════════════════════════════════ *}
  <div class="tab-pane active" id="tab-records">

    <h4 style="margin-top:18px;">Current Records
      <small class="text-muted">zone: {$zone|escape}</small>
    </h4>

    <div id="pdns-records-wrapper">
      {include file="modules/servers/powerdns_premium/templates/partials/_records_table.tpl"}
    </div>

    <hr>

    {* Add record form *}
    {if $licensed && !$quotaReached}
      <h4>Add Record</h4>
      {include file="modules/servers/powerdns_premium/templates/partials/_add_form.tpl"}
    {elseif $quotaReached}
      <div class="alert alert-warning">
        <i class="fa fa-ban"></i> Record quota reached ({$maxRecords} records). Delete existing records to add new ones.
      </div>
    {/if}

    <hr>

    {* Quick templates *}
    <h4>Quick-Apply Template</h4>
    {include file="modules/servers/powerdns_premium/templates/partials/_templates.tpl"}

  </div>{* /tab-records *}

  {* ═══════════════════════════════════════════════════════════════════════ *}
  {* TAB 2 – DNSSEC                                                         *}
  {* ═══════════════════════════════════════════════════════════════════════ *}
  {if $allowDNSSEC}
  <div class="tab-pane" id="tab-dnssec">
    {include file="modules/servers/powerdns_premium/templates/partials/_dnssec.tpl"}
  </div>
  {/if}

  {* ═══════════════════════════════════════════════════════════════════════ *}
  {* TAB 3 – Propagation                                                    *}
  {* ═══════════════════════════════════════════════════════════════════════ *}
  <div class="tab-pane" id="tab-propagation">
    {include file="modules/servers/powerdns_premium/templates/partials/_propagation.tpl"}
  </div>

  {* ═══════════════════════════════════════════════════════════════════════ *}
  {* TAB 4 – History                                                        *}
  {* ═══════════════════════════════════════════════════════════════════════ *}
  <div class="tab-pane" id="tab-history">
    {include file="modules/servers/powerdns_premium/templates/partials/_history.tpl"}
  </div>

  {* ═══════════════════════════════════════════════════════════════════════ *}
  {* TAB 5 – Import / Export                                                *}
  {* ═══════════════════════════════════════════════════════════════════════ *}
  <div class="tab-pane" id="tab-io">
    {include file="modules/servers/powerdns_premium/templates/partials/_io.tpl"}
  </div>

</div>{* /tab-content *}

{* ── Shared hidden fields (read by JS) ──────────────────────────────────── *}
<input type="hidden" id="pdns-service-id"  value="{$serviceId|escape}">
<input type="hidden" id="pdns-zone"        value="{$zone|escape}">
<input type="hidden" id="pdns-default-ttl" value="{$defaultTTL|escape}">
<input type="hidden" id="pdns-licensed"    value="{if $licensed}1{else}0{/if}">
<input type="hidden" id="pdns-token"       value="{$token|escape}">

{* ── Styles ──────────────────────────────────────────────────────────────── *}
<style>
  .pdns-ns-callout   { margin-bottom:18px; }
  .pdns-ns-row       { display:flex; align-items:center; gap:10px; margin-bottom:6px; }
  .pdns-ns-row code  { font-size:14px; }
  .pdns-tabs         { margin-top:8px; }
  .pdns-tab-content  { border:1px solid #ddd; border-top:none; padding:20px; border-radius:0 0 4px 4px; }
  .pdns-quota-bar    { margin-bottom:12px; }
  .pdns-content-wrap { word-break:break-all; max-width:280px; display:inline-block; }
  .pdns-badge-A      { background:#337ab7; }
  .pdns-badge-AAAA   { background:#5bc0de; }
  .pdns-badge-CNAME  { background:#9b59b6; }
  .pdns-badge-MX     { background:#f0ad4e; color:#333; }
  .pdns-badge-TXT    { background:#777; }
  .pdns-badge-SRV    { background:#d9534f; }
  .pdns-badge-CAA    { background:#e67e22; }
  .pdns-badge-NS     { background:#1abc9c; }
  .pdns-badge-ALIAS  { background:#2980b9; }
  .pdns-badge-PTR    { background:#7f8c8d; }
  span[class^="pdns-badge-"] { color:#fff; padding:2px 6px; border-radius:3px; font-size:11px; font-weight:700; }
  .pdns-edit-row input, .pdns-edit-row select { font-size:12px; }
  .pdns-spinner { display:none; margin-left:6px; }
  #pdns-propagation-results .resolver-row { padding:8px 0; border-bottom:1px solid #eee; }
  #pdns-propagation-results .resolver-row:last-child { border-bottom:none; }
  .pdns-ds-table td, .pdns-ds-table th { font-family:monospace; font-size:12px; }
  .pdns-history-action { text-transform:capitalize; }
</style>

{* ── JavaScript bundle ───────────────────────────────────────────────────── *}
<script src="{$WEB_ROOT}/modules/servers/powerdns_premium/assets/pdns-premium.js" defer></script>
<script>
(function() {
  /* Restore active tab from URL hash */
  var hash = window.location.hash;
  if (hash) {
    var tab = document.querySelector('.pdns-tabs a[href="' + hash + '"]');
    if (tab) { tab.click(); }
  }
  document.querySelectorAll('.pdns-tabs a').forEach(function(a) {
    a.addEventListener('shown.bs.tab', function() {
      window.location.hash = a.getAttribute('href');
    });
  });

  /* Copy-to-clipboard for nameservers */
  document.querySelectorAll('.pdns-copy-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var id   = btn.getAttribute('data-target');
      var text = document.getElementById(id).innerText;
      navigator.clipboard && navigator.clipboard.writeText(text).then(function() {
        btn.innerHTML = '<i class="fa fa-check"></i> Copied!';
        setTimeout(function() { btn.innerHTML = '<i class="fa fa-copy"></i> Copy'; }, 2000);
      });
    });
  });
}());
</script>
