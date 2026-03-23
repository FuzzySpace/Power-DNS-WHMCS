{* PowerDNS Client Area DNS Manager Template *}
{* Supports both plain-HTML form submit (fallback) and AJAX (no page reload). *}

<div class="powerdns-manager" id="pdns-manager">

  <div class="panel panel-default">
    <div class="panel-heading">
      <h3 class="panel-title">
        <i class="fa fa-globe"></i> DNS Manager &mdash; <strong>{$zone|escape}</strong>
      </h3>
    </div>

    <div class="panel-body">

      {* ------------------------------------------------------------------ *}
      {* Server-side alerts (shown on hard page load fallback)              *}
      {* ------------------------------------------------------------------ *}
      {if $error}
        <div class="alert alert-danger alert-dismissible" role="alert" id="pdns-alert-error">
          <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
          <i class="fa fa-exclamation-circle"></i> {$error|escape}
        </div>
      {/if}
      {if $success}
        <div class="alert alert-success alert-dismissible" role="alert" id="pdns-alert-success">
          <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
          <i class="fa fa-check-circle"></i> {$success|escape}
        </div>
      {/if}

      {* AJAX dynamic alert target *}
      <div id="pdns-alert" style="display:none;"></div>

      {* ------------------------------------------------------------------ *}
      {* Current records table                                               *}
      {* ------------------------------------------------------------------ *}
      <h4>Current DNS Records</h4>

      <div id="pdns-records-wrapper">
        {include file="modules/servers/powerdns/templates/_records_table.tpl" records=$records}
      </div>

      <hr>

      {* ------------------------------------------------------------------ *}
      {* Add record form                                                     *}
      {* ------------------------------------------------------------------ *}
      <h4>Add DNS Record</h4>
      <form method="post" action="" id="pdns-add-form" novalidate>
        {csrf_token}
        <input type="hidden" name="powerdns_action" value="add_record">
        <input type="hidden" name="powerdns_ajax"   value="1">
        <input type="hidden" name="service_id"      value="{$serviceId|escape}">

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
                <span class="input-group-addon pdns-zone-label">.{$zone|escape}</span>
              </div>
              <span class="help-block">
                Leave blank or use <code>@</code> for the zone apex.
                Use a bare label (e.g. <code>www</code>) for a subdomain.
              </span>
            </div>
          </div>
          <div class="col-sm-2">
            <div class="form-group">
              <label for="record_ttl">TTL&nbsp;(sec)</label>
              <input type="number" name="record_ttl" id="record_ttl" class="form-control"
                     value="{$defaultTTL|escape}" min="60" max="86400">
            </div>
          </div>
        </div>

        {* ---- A / AAAA ---- *}
        <div id="fields-A" class="pdns-type-fields">
          <div class="form-group" style="max-width:320px;">
            <label>IP Address</label>
            <input type="text" name="record_value" class="form-control pdns-a-value"
                   placeholder="93.184.216.34" autocomplete="off">
          </div>
        </div>

        {* ---- MX ---- *}
        <div id="fields-MX" class="pdns-type-fields" style="display:none;">
          <div class="row">
            <div class="col-sm-2">
              <div class="form-group">
                <label>Priority</label>
                <input type="number" name="record_priority" class="form-control"
                       value="10" min="0" max="65535">
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

        {* ---- TXT ---- *}
        <div id="fields-TXT" class="pdns-type-fields" style="display:none;">
          <div class="form-group" style="max-width:520px;">
            <label>Text Content</label>
            <textarea name="record_value" class="form-control pdns-txt-value" rows="2"
                      placeholder='v=spf1 include:_spf.example.com ~all'></textarea>
            <span class="help-block">Do not add surrounding quotes &mdash; they are added automatically.</span>
          </div>
        </div>

        {* ---- SRV ---- *}
        <div id="fields-SRV" class="pdns-type-fields" style="display:none;">
          <p class="help-block">
            Name format: <code>_service._proto</code>&nbsp;
            (e.g.&nbsp;<code>_sip._tcp</code>)
          </p>
          <div class="row">
            <div class="col-sm-2">
              <div class="form-group">
                <label>Priority</label>
                <input type="number" name="srv_priority" class="form-control"
                       value="10" min="0" max="65535">
              </div>
            </div>
            <div class="col-sm-2">
              <div class="form-group">
                <label>Weight</label>
                <input type="number" name="srv_weight" class="form-control"
                       value="0" min="0" max="65535">
              </div>
            </div>
            <div class="col-sm-2">
              <div class="form-group">
                <label>Port</label>
                <input type="number" name="srv_port" class="form-control"
                       placeholder="5060" min="1" max="65535">
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

    </div>{* /panel-body *}
  </div>{* /panel *}

</div>{* /powerdns-manager *}

{* ------------------------------------------------------------------ *}
{* Styles                                                              *}
{* ------------------------------------------------------------------ *}
<style>
  .powerdns-manager .pdns-content-wrap { word-break:break-all; }
  .powerdns-manager .pdns-zone-label   { font-size:12px; color:#777; }
  .powerdns-manager .table td          { vertical-align:middle; }
  .powerdns-manager .pdns-delete-btn   { white-space:nowrap; }
</style>

{* ------------------------------------------------------------------ *}
{* JavaScript – progressive enhancement: AJAX if fetch available      *}
{* ------------------------------------------------------------------ *}
<script>
(function () {
  "use strict";

  /* ---------------------------------------------------------------- */
  /* Record type field toggling                                        */
  /* ---------------------------------------------------------------- */
  function pdnsShowFields(type) {
    document.querySelectorAll(".pdns-type-fields").forEach(function (el) {
      el.style.display = "none";
    });
    var target = (type === "AAAA") ? "A" : type;
    var el = document.getElementById("fields-" + target);
    if (el) { el.style.display = ""; }

    /* Update A-field placeholder */
    var ipInput = document.querySelector(".pdns-a-value");
    if (ipInput) {
      ipInput.placeholder = (type === "AAAA")
        ? "2606:2800:220:1:248:1893:25c8:1946"
        : "93.184.216.34";
    }
  }
  window.pdnsShowFields = pdnsShowFields; // expose for inline onchange

  /* ---------------------------------------------------------------- */
  /* Alert helper                                                      */
  /* ---------------------------------------------------------------- */
  function pdnsAlert(type, message) {
    var el = document.getElementById("pdns-alert");
    el.className = "alert alert-" + type + " alert-dismissible";
    el.innerHTML = '<button type="button" class="close" onclick="this.parentNode.style.display=\'none\'">'
                 + '<span>&times;</span></button>'
                 + '<i class="fa fa-' + (type === 'success' ? 'check' : 'exclamation') + '-circle"></i> '
                 + pdnsEscape(message);
    el.style.display = "";
    window.scrollTo(0, el.getBoundingClientRect().top + window.pageYOffset - 80);
  }

  function pdnsEscape(str) {
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  /* ---------------------------------------------------------------- */
  /* Render a single record row                                        */
  /* ---------------------------------------------------------------- */
  function pdnsBadgeClass(type) {
    var map = {A:'primary', AAAA:'info', MX:'warning', TXT:'default', SRV:'danger'};
    return map[type] || 'default';
  }

  function pdnsRenderRow(rec) {
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td><code>' + pdnsEscape(rec.name) + '</code></td>'
    + '<td><span class="label label-' + pdnsBadgeClass(rec.type) + '">' + pdnsEscape(rec.type) + '</span></td>'
    + '<td>' + pdnsEscape(String(rec.ttl)) + '</td>'
    + '<td><code class="pdns-content-wrap">' + pdnsEscape(rec.content) + '</code></td>'
    + '<td class="text-center">'
    +   '<button type="button" class="btn btn-xs btn-danger pdns-delete-btn"'
    +     ' data-name="'    + pdnsEscape(rec.name)    + '"'
    +     ' data-type="'    + pdnsEscape(rec.type)    + '"'
    +     ' data-content="' + pdnsEscape(rec.content) + '">'
    +     '<i class="fa fa-trash"></i> Delete'
    +   '</button>'
    + '</td>';
    return tr;
  }

  /* ---------------------------------------------------------------- */
  /* Refresh the records table via AJAX                               */
  /* ---------------------------------------------------------------- */
  function pdnsRefreshTable(newRecord) {
    var tbody = document.querySelector('#pdns-records-wrapper tbody');
    if (!tbody) return;

    if (newRecord) {
      // Append new row immediately (optimistic UI)
      tbody.appendChild(pdnsRenderRow(newRecord));

      // Hide empty-state message if present
      var empty = document.getElementById('pdns-empty-msg');
      if (empty) { empty.style.display = 'none'; }

      // Ensure table is visible
      var tbl = tbody.closest('table');
      if (tbl) { tbl.style.display = ''; }
    }
  }

  /* ---------------------------------------------------------------- */
  /* AJAX: Add record                                                  */
  /* ---------------------------------------------------------------- */
  var addForm = document.getElementById('pdns-add-form');
  if (addForm && window.fetch) {
    addForm.addEventListener('submit', function (e) {
      e.preventDefault();

      /* Client-side validation */
      var type = document.getElementById('record_type').value;
      if (type === 'SRV') {
        var port = addForm.querySelector('[name="srv_port"]').value;
        if (!port || parseInt(port, 10) < 1) {
          pdnsAlert('danger', 'Please enter a valid SRV port number.');
          return;
        }
      }
      var valueOk = false;
      addForm.querySelectorAll('[name="record_value"]').forEach(function (f) {
        if (f.offsetParent !== null && f.value.trim() !== '') { valueOk = true; }
      });
      if (!valueOk) {
        pdnsAlert('danger', 'Please enter a value for the record.');
        return;
      }

      var btn     = document.getElementById('pdns-add-btn');
      var spinner = document.getElementById('pdns-add-spinner');
      btn.disabled  = true;
      spinner.style.display = '';

      var fd = new FormData(addForm);

      fetch(window.location.href, {
        method:      'POST',
        body:        fd,
        credentials: 'same-origin',
      })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        btn.disabled  = false;
        spinner.style.display = 'none';

        if (data.success) {
          pdnsAlert('success', 'Record added successfully.');
          pdnsRefreshTable(data.record);
          addForm.reset();
          pdnsShowFields('A'); // reset to first tab
        } else {
          pdnsAlert('danger', data.error || 'An unknown error occurred.');
        }
      })
      .catch(function (err) {
        btn.disabled  = false;
        spinner.style.display = 'none';
        pdnsAlert('danger', 'Request failed: ' + err.message);
      });
    });
  }

  /* ---------------------------------------------------------------- */
  /* AJAX: Delete record (event delegation)                           */
  /* ---------------------------------------------------------------- */
  var wrapper = document.getElementById('pdns-records-wrapper');
  if (wrapper && window.fetch) {
    wrapper.addEventListener('click', function (e) {
      var btn = e.target.closest('.pdns-delete-btn');
      if (!btn) return;

      if (!confirm('Delete this record?')) return;

      var name    = btn.getAttribute('data-name');
      var type    = btn.getAttribute('data-type');
      var content = btn.getAttribute('data-content');
      var row     = btn.closest('tr');

      /* Find the CSRF token from the add-form */
      var tokenInput = document.querySelector('#pdns-add-form [name="token"]')
                    || document.querySelector('#pdns-add-form input[type="hidden"][name!="powerdns_action"][name!="powerdns_ajax"][name!="service_id"]');
      var token = tokenInput ? tokenInput.value : '';

      var serviceId = document.querySelector('[name="service_id"]').value;

      var fd = new FormData();
      fd.append('powerdns_action',  'delete_record');
      fd.append('powerdns_ajax',    '1');
      fd.append('service_id',       serviceId);
      fd.append('token',            token);
      fd.append('record_type',      type);
      fd.append('record_name',      name);
      fd.append('record_content',   content);

      btn.disabled = true;
      btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i>';

      fetch(window.location.href, {
        method:      'POST',
        body:        fd,
        credentials: 'same-origin',
      })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.success) {
          if (row) { row.remove(); }
          pdnsAlert('success', 'Record deleted successfully.');

          /* Show empty message if table is now empty */
          var remaining = document.querySelectorAll('#pdns-records-wrapper tbody tr');
          if (remaining.length === 0) {
            var empty = document.getElementById('pdns-empty-msg');
            if (empty) { empty.style.display = ''; }
          }
        } else {
          btn.disabled  = false;
          btn.innerHTML = '<i class="fa fa-trash"></i> Delete';
          pdnsAlert('danger', data.error || 'An unknown error occurred.');
        }
      })
      .catch(function (err) {
        btn.disabled  = false;
        btn.innerHTML = '<i class="fa fa-trash"></i> Delete';
        pdnsAlert('danger', 'Request failed: ' + err.message);
      });
    });
  }

  /* ---------------------------------------------------------------- */
  /* Fallback: plain form submit for non-AJAX delete buttons          */
  /* (rendered by the server-side partial when JS is not available)   */
  /* ---------------------------------------------------------------- */

}());
</script>
