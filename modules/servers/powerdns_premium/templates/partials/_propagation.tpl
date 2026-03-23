{* DNS propagation checker tab *}
<h4 style="margin-top:18px;">DNS Propagation Checker</h4>
<p class="text-muted" style="font-size:13px;">
  Query public resolvers to see if your DNS records have propagated globally.
  Results are live — changes may take up to 48 hours to fully propagate.
</p>

<form id="pdns-prop-form" class="form-inline" style="margin-bottom:18px;">
  {csrf_token}
  <input type="hidden" name="pdns_action" value="check_propagation">
  <input type="hidden" name="pdns_p_ajax"  value="1">
  <input type="hidden" name="service_id"   value="{$serviceId|escape}">

  <div class="form-group" style="margin-right:8px;">
    <label class="sr-only">Name</label>
    <div class="input-group">
      <input type="text" name="check_name" id="pdns-prop-name" class="form-control"
             placeholder="@ or subdomain" style="width:200px;">
      <span class="input-group-addon" style="font-size:11px;">.{$zone|escape}</span>
    </div>
  </div>
  <div class="form-group" style="margin-right:8px;">
    <label class="sr-only">Type</label>
    <select name="check_type" class="form-control">
      {foreach from=$recordTypes item=rt}
        <option value="{$rt|escape}">{$rt|escape}</option>
      {/foreach}
    </select>
  </div>
  <button type="submit" class="btn btn-primary" id="pdns-prop-btn">
    <i class="fa fa-search"></i> Check Propagation
  </button>
  <span class="pdns-spinner" id="pdns-prop-spinner"><i class="fa fa-spinner fa-spin"></i> Querying resolvers&hellip;</span>
</form>

<div id="pdns-propagation-results" style="display:none;">
  <h5>Results for <strong id="pdns-prop-display-name"></strong>
    &nbsp;<span class="label label-default" id="pdns-prop-display-type"></span>
  </h5>
  <div id="pdns-prop-rows"></div>
</div>

<script>
(function() {
  var form    = document.getElementById('pdns-prop-form');
  var spinner = document.getElementById('pdns-prop-spinner');
  var btn     = document.getElementById('pdns-prop-btn');
  var results = document.getElementById('pdns-propagation-results');
  var rows    = document.getElementById('pdns-prop-rows');
  var zone    = document.getElementById('pdns-zone').value;

  if (form && window.fetch) {
    form.addEventListener('submit', function(e) {
      e.preventDefault();
      var nameInput = document.getElementById('pdns-prop-name');
      var name = nameInput.value.trim();
      if (!name || name === '@') { name = zone; }
      else if (name.slice(-1) !== '.') { name = name + '.' + zone; }

      var typeEl = form.querySelector('[name="check_type"]');
      document.getElementById('pdns-prop-display-name').textContent = name;
      document.getElementById('pdns-prop-display-type').textContent = typeEl ? typeEl.value : '';

      btn.disabled = true; spinner.style.display = '';
      results.style.display = 'none'; rows.innerHTML = '';

      var fd = new FormData(form);
      fd.set('check_name', name);

      fetch(window.location.href, { method:'POST', body:fd, credentials:'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
          btn.disabled = false; spinner.style.display = 'none';
          if (!data.success) { pdnsPremiumAlert('danger', data.error || 'Check failed.'); return; }
          results.style.display = '';
          data.results.forEach(function(res) {
            var ok     = res.status === 'ok';
            var nx     = res.status === 'nxdomain';
            var icon   = ok ? 'check-circle' : (nx ? 'minus-circle' : 'times-circle');
            var color  = ok ? '#2ecc71'      : (nx ? '#f0ad4e'      : '#e74c3c');
            var html   = '<div class="resolver-row">'
                       + '<span style="color:' + color + ';margin-right:8px;"><i class="fa fa-' + icon + '"></i></span>'
                       + '<strong>' + pdnsEscape(res.resolver) + '</strong>&nbsp;';
            if (ok && res.answers.length) {
              res.answers.forEach(function(ans) {
                html += '<br><span style="margin-left:24px;font-family:monospace;font-size:12px;">'
                      + pdnsEscape(ans.type) + ' ' + pdnsEscape(ans.data)
                      + ' <span class="text-muted">(TTL ' + ans.ttl + ')</span></span>';
              });
            } else if (nx) {
              html += '<span class="text-muted">NXDOMAIN (no records found)</span>';
            } else {
              html += '<span class="text-danger">' + pdnsEscape(res.error || 'Error') + '</span>';
            }
            html += '</div>';
            rows.innerHTML += html;
          });
        })
        .catch(function(err) {
          btn.disabled = false; spinner.style.display = 'none';
          pdnsPremiumAlert('danger', 'Request failed: ' + err.message);
        });
    });
  }

  function pdnsEscape(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }
}());
</script>
