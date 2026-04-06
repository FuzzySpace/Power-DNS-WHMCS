{* PowerDNS Client Area – DNS Manager
   Nameserver info + full record manager on one page.
   AJAX add/delete with plain-form fallback.
*}

<div class="powerdns-manager" id="pdns-manager">

  {* ── Back to overview ───────────────────────────────────────────────── *}
  <p style="margin-bottom:16px;">
    <a href="{$serviceUrl|escape}" class="btn btn-default btn-sm">
      <i class="fa fa-arrow-left"></i> Back to Zone Overview
    </a>
  </p>

  {* ── Zone / Nameserver info ─────────────────────────────────────────── *}
  <div class="panel panel-info">
    <div class="panel-heading">
      <h4 class="panel-title">
        <i class="fa fa-globe"></i> {$zone|escape}
        &nbsp;<span class="label label-success" style="font-size:11px;vertical-align:middle;">Active</span>
      </h4>
    </div>
    <div class="panel-body">
      <p class="text-muted" style="margin-bottom:10px;">
        Set the following nameservers at your domain registrar.
        Changes can take up to 24&nbsp;hours to propagate.
      </p>
      {if $nameservers}
        {foreach from=$nameservers item=ns}
          <code style="margin-right:16px; font-size:14px;">{$ns|escape}</code>
        {/foreach}
      {else}
        <em class="text-muted">No nameservers configured &mdash; add them in the product module settings.</em>
      {/if}
    </div>
  </div>

  {* ── DNS Records panel ───────────────────────────────────────────────── *}
  <div class="panel panel-default">
    <div class="panel-heading">
      <h3 class="panel-title"><i class="fa fa-list-ul"></i> DNS Records</h3>
    </div>

    <div class="panel-body">

      {* Alerts *}
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

      {* ── Records table (inlined – no {include}) ──────────────────────── *}
      <div id="pdns-records-wrapper">
        {if $records}
          <div class="table-responsive">
            <table class="table table-striped table-hover table-condensed" id="pdns-records-table">
              <thead>
                <tr>
                  <th>Name</th><th>Type</th><th>TTL</th><th>Content</th>
                  <th class="text-center">Action</th>
                </tr>
              </thead>
              <tbody>
                {foreach from=$records item=rec}
                  <tr>
                    <td><code>{$rec.name|escape}</code></td>
                    <td>
                      <span class="label label-{if $rec.type eq 'A'}primary{elseif $rec.type eq 'AAAA'}info{elseif $rec.type eq 'MX'}warning{elseif $rec.type eq 'TXT'}default{else}danger{/if}">
                        {$rec.type|escape}
                      </span>
                    </td>
                    <td>{$rec.ttl|escape}</td>
                    <td><code class="pdns-content-wrap">{$rec.content|escape}</code></td>
                    <td class="text-center">
                      <button type="button" class="btn btn-xs btn-danger pdns-delete-btn"
                              data-name="{$rec.name|escape}"
                              data-type="{$rec.type|escape}"
                              data-content="{$rec.content|escape}">
                        <i class="fa fa-trash"></i> Delete
                      </button>
                    </td>
                  </tr>
                {/foreach}
              </tbody>
            </table>
          </div>
          <p class="text-muted" id="pdns-empty-msg" style="display:none;">
            <em>No DNS records found. Add your first record below.</em>
          </p>
        {else}
          <p class="text-muted" id="pdns-empty-msg">
            <em>No DNS records found. Add your first record below.</em>
          </p>
        {/if}
      </div>

      <hr>

      {* ── Add record form ─────────────────────────────────────────────── *}
      <h4>Add DNS Record</h4>
      <form method="post" action="" id="pdns-add-form" novalidate>
        {csrf_token}
        <input type="hidden" name="powerdns_action" value="add_record">
        <input type="hidden" name="service_id" value="{$serviceId|escape}">

        <div class="form-group">
          <label for="record_type">Record Type</label>
          <select name="record_type" id="record_type" class="form-control" style="max-width:180px;"
                  onchange="pdnsShowFields(this.value);">
            <option value="A">A &mdash; IPv4 Address</option>
            <option value="AAAA">AAAA &mdash; IPv6 Address</option>
            <option value="MX">MX &mdash; Mail Exchange</option>
            <option value="TXT">TXT &mdash; Text Record</option>
            <option value="SRV">SRV &mdash; Service Locator</option>
          </select>
        </div>

        <div class="row">
          <div class="col-sm-5">
            <div class="form-group">
              <label for="record_name">Name / Host</label>
              <div class="input-group">
                <input type="text" name="record_name" id="record_name" class="form-control"
                       placeholder="@ or subdomain">
                <span class="input-group-addon" style="font-size:12px;color:#777;">.{$zone|escape}</span>
              </div>
              <span class="help-block">Leave blank or use <code>@</code> for the zone apex.</span>
            </div>
          </div>
          <div class="col-sm-2">
            <div class="form-group">
              <label for="record_ttl">TTL (sec)</label>
              <input type="number" name="record_ttl" id="record_ttl" class="form-control"
                     value="{$defaultTTL|escape}" min="60" max="86400">
            </div>
          </div>
        </div>

        <div id="fields-A" class="pdns-type-fields">
          <div class="form-group" style="max-width:320px;">
            <label>IP Address</label>
            <input type="text" name="record_value" class="form-control pdns-a-value"
                   placeholder="93.184.216.34" autocomplete="off">
          </div>
        </div>

        <div id="fields-MX" class="pdns-type-fields" style="display:none;">
          <div class="row">
            <div class="col-sm-2">
              <div class="form-group">
                <label>Priority</label>
                <input type="number" name="record_priority" class="form-control" value="10" min="0" max="65535">
              </div>
            </div>
            <div class="col-sm-5">
              <div class="form-group">
                <label>Mail Server (FQDN)</label>
                <input type="text" name="record_value" class="form-control pdns-mx-value"
                       placeholder="mail.example.com" autocomplete="off">
              </div>
            </div>
          </div>
        </div>

        <div id="fields-TXT" class="pdns-type-fields" style="display:none;">
          <div class="form-group" style="max-width:520px;">
            <label>Text Content</label>
            <textarea name="record_value" class="form-control pdns-txt-value" rows="2"
                      placeholder="v=spf1 include:_spf.example.com ~all"></textarea>
            <span class="help-block">Do not add surrounding quotes &mdash; they are added automatically.</span>
          </div>
        </div>

        <div id="fields-SRV" class="pdns-type-fields" style="display:none;">
          <p class="help-block">Name format: <code>_service._proto</code> (e.g. <code>_sip._tcp</code>)</p>
          <div class="row">
            <div class="col-sm-2">
              <div class="form-group">
                <label>Priority</label>
                <input type="number" name="srv_priority" class="form-control" value="10" min="0" max="65535">
              </div>
            </div>
            <div class="col-sm-2">
              <div class="form-group">
                <label>Weight</label>
                <input type="number" name="srv_weight" class="form-control" value="0" min="0" max="65535">
              </div>
            </div>
            <div class="col-sm-2">
              <div class="form-group">
                <label>Port</label>
                <input type="number" name="srv_port" class="form-control" placeholder="5060" min="1" max="65535">
              </div>
            </div>
            <div class="col-sm-4">
              <div class="form-group">
                <label>Target (FQDN)</label>
                <input type="text" name="record_value" class="form-control pdns-srv-value"
                       placeholder="sip.example.com" autocomplete="off">
              </div>
            </div>
          </div>
        </div>

        <button type="submit" class="btn btn-success" id="pdns-add-btn">
          <i class="fa fa-plus"></i> Add Record
        </button>
        <span id="pdns-add-spinner" style="display:none; margin-left:8px;">
          <i class="fa fa-spinner fa-spin"></i> Saving&hellip;
        </span>

      </form>
    </div>
  </div>

</div>{* /powerdns-manager *}

<style>
  .powerdns-manager .pdns-content-wrap { word-break:break-all; }
  .powerdns-manager .table td          { vertical-align:middle; }
</style>

<script>
(function () {
  "use strict";

  function pdnsShowFields(type) {
    document.querySelectorAll(".pdns-type-fields").forEach(function (el) { el.style.display = "none"; });
    var target = (type === "AAAA") ? "A" : type;
    var el = document.getElementById("fields-" + target);
    if (el) el.style.display = "";
    var ip = document.querySelector(".pdns-a-value");
    if (ip) ip.placeholder = (type === "AAAA") ? "2606:2800:220:1:248:1893:25c8:1946" : "93.184.216.34";
  }
  window.pdnsShowFields = pdnsShowFields;

  function pdnsEscape(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function pdnsAlert(type, msg) {
    var el = document.getElementById("pdns-alert");
    el.className = "alert alert-" + type + " alert-dismissible";
    el.innerHTML = '<button type="button" class="close" onclick="this.parentNode.style.display=\'none\'">&times;</button>'
      + '<i class="fa fa-' + (type === 'success' ? 'check' : 'exclamation') + '-circle"></i> ' + pdnsEscape(msg);
    el.style.display = "";
    el.scrollIntoView({behavior:"smooth", block:"nearest"});
  }

  function pdnsBadge(type) {
    return {A:'primary',AAAA:'info',MX:'warning',TXT:'default',SRV:'danger'}[type] || 'default';
  }

  function pdnsAddRow(rec) {
    var tbody = document.querySelector('#pdns-records-table tbody');
    if (!tbody) {
      // Table didn't exist (was empty state) – reload to show properly
      window.location.reload();
      return;
    }
    var tr = document.createElement('tr');
    tr.innerHTML = '<td><code>' + pdnsEscape(rec.name) + '</code></td>'
      + '<td><span class="label label-' + pdnsBadge(rec.type) + '">' + pdnsEscape(rec.type) + '</span></td>'
      + '<td>' + pdnsEscape(String(rec.ttl)) + '</td>'
      + '<td><code class="pdns-content-wrap">' + pdnsEscape(rec.content) + '</code></td>'
      + '<td class="text-center"><button type="button" class="btn btn-xs btn-danger pdns-delete-btn"'
      + ' data-name="' + pdnsEscape(rec.name) + '" data-type="' + pdnsEscape(rec.type)
      + '" data-content="' + pdnsEscape(rec.content) + '"><i class="fa fa-trash"></i> Delete</button></td>';
    tbody.appendChild(tr);
    var empty = document.getElementById('pdns-empty-msg');
    if (empty) empty.style.display = 'none';
  }

  /* Add record */
  var addForm = document.getElementById('pdns-add-form');
  if (addForm && window.fetch) {
    addForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var type = document.getElementById('record_type').value;
      if (type === 'SRV') {
        var port = addForm.querySelector('[name="srv_port"]').value;
        if (!port || parseInt(port, 10) < 1) { pdnsAlert('danger', 'Please enter a valid SRV port.'); return; }
      }
      var ok = false;
      addForm.querySelectorAll('[name="record_value"]').forEach(function (f) {
        if (f.offsetParent !== null && f.value.trim()) ok = true;
      });
      if (!ok) { pdnsAlert('danger', 'Please enter a value for the record.'); return; }

      var btn = document.getElementById('pdns-add-btn');
      var spin = document.getElementById('pdns-add-spinner');
      btn.disabled = true; spin.style.display = '';

      var fd = new FormData(addForm);
      fd.append('powerdns_ajax', '1');

      fetch(window.location.href, {method:'POST', body:fd, credentials:'same-origin'})
        .then(function(r){ return r.json(); })
        .then(function(data){
          btn.disabled = false; spin.style.display = 'none';
          if (data.success) {
            pdnsAlert('success', 'Record added successfully.');
            pdnsAddRow(data.record);
            addForm.reset();
            pdnsShowFields('A');
          } else {
            pdnsAlert('danger', data.error || 'Unknown error.');
          }
        })
        .catch(function(err){
          btn.disabled = false; spin.style.display = 'none';
          pdnsAlert('danger', 'Request failed: ' + err.message);
        });
    });
  }

  /* Delete record */
  var wrapper = document.getElementById('pdns-records-wrapper');
  if (wrapper && window.fetch) {
    wrapper.addEventListener('click', function (e) {
      var btn = e.target.closest('.pdns-delete-btn');
      if (!btn || !confirm('Delete this record?')) return;

      var name = btn.getAttribute('data-name');
      var type = btn.getAttribute('data-type');
      var content = btn.getAttribute('data-content');
      var row = btn.closest('tr');

      var tokenEl = addForm ? (addForm.querySelector('[name="token"]') || addForm.querySelector('input[type="hidden"]:not([name="powerdns_action"]):not([name="service_id"])')) : null;
      var serviceIdEl = document.querySelector('[name="service_id"]');

      var fd = new FormData();
      fd.append('powerdns_action', 'delete_record');
      fd.append('powerdns_ajax',   '1');
      fd.append('service_id',      serviceIdEl ? serviceIdEl.value : '');
      fd.append('token',           tokenEl ? tokenEl.value : '');
      fd.append('record_type',     type);
      fd.append('record_name',     name);
      fd.append('record_content',  content);

      btn.disabled = true;
      btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i>';

      fetch(window.location.href, {method:'POST', body:fd, credentials:'same-origin'})
        .then(function(r){ return r.json(); })
        .then(function(data){
          if (data.success) {
            if (row) row.remove();
            pdnsAlert('success', 'Record deleted.');
            if (!document.querySelector('#pdns-records-wrapper tbody tr')) {
              var empty = document.getElementById('pdns-empty-msg');
              if (empty) empty.style.display = '';
            }
          } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-trash"></i> Delete';
            pdnsAlert('danger', data.error || 'Unknown error.');
          }
        })
        .catch(function(err){
          btn.disabled = false;
          btn.innerHTML = '<i class="fa fa-trash"></i> Delete';
          pdnsAlert('danger', 'Request failed: ' + err.message);
        });
    });
  }

}());
</script>
