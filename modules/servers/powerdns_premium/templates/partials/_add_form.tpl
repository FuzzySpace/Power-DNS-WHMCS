{* Add DNS record form *}
<form id="pdns-add-form" method="post" action="" novalidate>
  {csrf_token}
  <input type="hidden" name="pdns_action"  value="add_record">
  <input type="hidden" name="service_id"   value="{$serviceId|escape}">
  {* pdns_p_ajax added by JS so plain form-submit falls through to ClientArea() *}

  <div class="row">
    <div class="col-sm-3">
      <div class="form-group">
        <label>Type</label>
        <select name="record_type" id="pdns-record-type" class="form-control" onchange="pdnsShowFields(this.value);">
          {foreach from=$recordTypes item=rt}
            <option value="{$rt|escape}">{$rt|escape}</option>
          {/foreach}
        </select>
      </div>
    </div>
    <div class="col-sm-4">
      <div class="form-group">
        <label>Name / Host</label>
        <div class="input-group">
          <input type="text" name="record_name" id="pdns-record-name" class="form-control" placeholder="@ or subdomain">
          <span class="input-group-addon" style="font-size:11px;color:#777;">.{$zone|escape}</span>
        </div>
        <span class="help-block" style="font-size:11px;">Leave blank or <code>@</code> for zone apex.</span>
      </div>
    </div>
    <div class="col-sm-2">
      <div class="form-group">
        <label>TTL (sec)</label>
        <input type="number" name="record_ttl" class="form-control" value="{$defaultTTL|escape}" min="60" max="86400">
      </div>
    </div>
  </div>

  {* ── A ─────────────────────────────────────────────────────── *}
  <div id="pdns-fields-A" class="pdns-type-fields">
    <div class="form-group" style="max-width:320px;">
      <label>IPv4 Address</label>
      <input type="text" name="record_value" class="form-control pdns-val-a" placeholder="93.184.216.34">
    </div>
  </div>

  {* ── AAAA ──────────────────────────────────────────────────── *}
  <div id="pdns-fields-AAAA" class="pdns-type-fields" style="display:none;">
    <div class="form-group" style="max-width:420px;">
      <label>IPv6 Address</label>
      <input type="text" name="record_value" class="form-control" placeholder="2606:2800:220:1:248:1893:25c8:1946">
    </div>
  </div>

  {* ── CNAME / ALIAS / PTR / NS ─────────────────────────────── *}
  <div id="pdns-fields-CNAME" class="pdns-type-fields" style="display:none;">
    <div class="form-group" style="max-width:380px;">
      <label>Target (FQDN)</label>
      <input type="text" name="record_value" class="form-control" placeholder="target.example.com">
    </div>
  </div>

  {* ── MX ────────────────────────────────────────────────────── *}
  <div id="pdns-fields-MX" class="pdns-type-fields" style="display:none;">
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
          <input type="text" name="record_value" class="form-control" placeholder="mail.example.com">
        </div>
      </div>
    </div>
  </div>

  {* ── TXT ───────────────────────────────────────────────────── *}
  <div id="pdns-fields-TXT" class="pdns-type-fields" style="display:none;">
    <div class="form-group" style="max-width:520px;">
      <label>Text Content</label>
      <textarea name="record_value" class="form-control" rows="2"
                placeholder='v=spf1 include:_spf.example.com ~all'></textarea>
      <span class="help-block" style="font-size:11px;">Surrounding quotes are added automatically.</span>
    </div>
  </div>

  {* ── SRV ───────────────────────────────────────────────────── *}
  <div id="pdns-fields-SRV" class="pdns-type-fields" style="display:none;">
    <p class="help-block" style="font-size:11px;">Name: <code>_service._proto</code> e.g. <code>_sip._tcp</code></p>
    <div class="row">
      <div class="col-sm-2"><div class="form-group"><label>Priority</label>
        <input type="number" name="srv_priority" class="form-control" value="10" min="0" max="65535"></div></div>
      <div class="col-sm-2"><div class="form-group"><label>Weight</label>
        <input type="number" name="srv_weight" class="form-control" value="0" min="0" max="65535"></div></div>
      <div class="col-sm-2"><div class="form-group"><label>Port</label>
        <input type="number" name="srv_port" class="form-control" placeholder="5060" min="1" max="65535"></div></div>
      <div class="col-sm-4"><div class="form-group"><label>Target (FQDN)</label>
        <input type="text" name="record_value" class="form-control" placeholder="sip.example.com"></div></div>
    </div>
  </div>

  {* ── CAA ───────────────────────────────────────────────────── *}
  <div id="pdns-fields-CAA" class="pdns-type-fields" style="display:none;">
    <div class="row">
      <div class="col-sm-2"><div class="form-group"><label>Flag</label>
        <input type="number" name="caa_flag" class="form-control" value="0" min="0" max="255"></div></div>
      <div class="col-sm-3"><div class="form-group"><label>Tag</label>
        <select name="caa_tag" class="form-control">
          <option value="issue">issue</option>
          <option value="issuewild">issuewild</option>
          <option value="iodef">iodef</option>
        </select></div></div>
      <div class="col-sm-5"><div class="form-group"><label>Value</label>
        <input type="text" name="record_value" class="form-control" placeholder="letsencrypt.org"></div></div>
    </div>
  </div>

  <button type="submit" class="btn btn-success" id="pdns-add-btn">
    <i class="fa fa-plus"></i> Add Record
  </button>
  <span class="pdns-spinner" id="pdns-add-spinner"><i class="fa fa-spinner fa-spin"></i> Saving&hellip;</span>

</form>
