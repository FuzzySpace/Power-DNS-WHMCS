{* Import / Export tab *}
<div class="row" style="margin-top:18px;">

  {* ── Export ────────────────────────────────────────────────── *}
  <div class="col-sm-5">
    <div class="panel panel-default">
      <div class="panel-heading"><i class="fa fa-download"></i> Export Zone</div>
      <div class="panel-body">
        <p class="text-muted" style="font-size:13px;">
          Download your zone records as a CSV spreadsheet or a standard BIND zone file.
        </p>
        <div class="btn-group" id="pdns-export-btns">
          <button class="btn btn-default pdns-export-btn" data-format="csv">
            <i class="fa fa-file-text-o"></i> Export CSV
          </button>
          <button class="btn btn-default pdns-export-btn" data-format="bind">
            <i class="fa fa-file-code-o"></i> Export BIND Zone
          </button>
        </div>
        <span class="pdns-spinner" id="pdns-export-spinner"><i class="fa fa-spinner fa-spin"></i></span>
      </div>
    </div>
  </div>

  {* ── Import ─────────────────────────────────────────────────── *}
  {if $licensed}
  <div class="col-sm-7">
    <div class="panel panel-default">
      <div class="panel-heading"><i class="fa fa-upload"></i> Import from CSV</div>
      <div class="panel-body">
        <p class="text-muted" style="font-size:13px;">
          Upload a CSV file with columns: <code>name, type, ttl, content</code>.
          Existing records with the same name+type will be replaced.
          Maximum {500} records per import.
        </p>
        <form id="pdns-import-form" method="post" enctype="multipart/form-data">
          {csrf_token}
          <input type="hidden" name="pdns_action" value="import_csv">
          <input type="hidden" name="pdns_p_ajax"  value="1">
          <input type="hidden" name="service_id"   value="{$serviceId|escape}">
          <div class="form-group">
            <label>Select CSV file</label>
            <input type="file" name="csv_file" accept=".csv,text/csv" required>
          </div>
          <button type="submit" class="btn btn-warning" id="pdns-import-btn">
            <i class="fa fa-upload"></i> Import Records
          </button>
          <span class="pdns-spinner" id="pdns-import-spinner"><i class="fa fa-spinner fa-spin"></i> Importing&hellip;</span>
        </form>
        <div id="pdns-import-result" style="display:none;margin-top:12px;"></div>
      </div>
    </div>
  </div>
  {else}
  <div class="col-sm-7">
    <div class="panel panel-default">
      <div class="panel-heading"><i class="fa fa-upload"></i> Import from CSV</div>
      <div class="panel-body">
        <div class="alert alert-warning" style="margin:0;">
          <i class="fa fa-lock"></i> A valid license is required to import records.
        </div>
      </div>
    </div>
  </div>
  {/if}

</div>

<script>
(function() {
  var sid   = document.getElementById('pdns-service-id').value;
  var token = document.getElementById('pdns-token').value;

  /* ── Export ─────────────────────────────────────────────────── */
  document.querySelectorAll('.pdns-export-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var fmt     = btn.getAttribute('data-format');
      var spinner = document.getElementById('pdns-export-spinner');
      spinner.style.display = '';

      var fd = new FormData();
      fd.append('pdns_action', 'export_zone');
      fd.append('pdns_p_ajax', '1');
      fd.append('service_id',  sid);
      fd.append('token',       token);
      fd.append('format',      fmt);

      fetch(window.location.href, { method:'POST', body:fd, credentials:'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
          spinner.style.display = 'none';
          if (!data.success) { pdnsPremiumAlert('danger', data.error || 'Export failed.'); return; }
          /* Trigger browser download from base64 content */
          var bytes   = atob(data.content);
          var arr     = new Uint8Array(bytes.length);
          for (var i = 0; i < bytes.length; i++) arr[i] = bytes.charCodeAt(i);
          var blob    = new Blob([arr], { type: data.mime });
          var url     = URL.createObjectURL(blob);
          var a       = document.createElement('a');
          a.href      = url;
          a.download  = data.filename;
          document.body.appendChild(a);
          a.click();
          document.body.removeChild(a);
          URL.revokeObjectURL(url);
        })
        .catch(function(err) {
          spinner.style.display = 'none';
          pdnsPremiumAlert('danger', 'Export failed: ' + err.message);
        });
    });
  });

  /* ── Import ─────────────────────────────────────────────────── */
  var importForm = document.getElementById('pdns-import-form');
  if (importForm && window.fetch) {
    importForm.addEventListener('submit', function(e) {
      e.preventDefault();
      var fileInput = importForm.querySelector('[name="csv_file"]');
      if (!fileInput || !fileInput.files.length) {
        pdnsPremiumAlert('danger', 'Please select a CSV file.');
        return;
      }
      if (!confirm('Import records from CSV? Existing records of the same name+type will be replaced.')) return;

      var spinner = document.getElementById('pdns-import-spinner');
      var btn     = document.getElementById('pdns-import-btn');
      var result  = document.getElementById('pdns-import-result');
      btn.disabled = true; spinner.style.display = '';

      var fd = new FormData(importForm);

      fetch(window.location.href, { method:'POST', body:fd, credentials:'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
          btn.disabled = false; spinner.style.display = 'none';
          result.style.display = '';
          if (data.success) {
            var html = '<div class="alert alert-success"><i class="fa fa-check-circle"></i> '
                     + data.imported + ' record(s) imported successfully.</div>';
            if (data.errors && data.errors.length) {
              html += '<div class="alert alert-warning"><strong>Warnings:</strong><ul>';
              data.errors.forEach(function(err) {
                html += '<li>Line ' + err.line + ': ' + esc(err.message) + '</li>';
              });
              html += '</ul></div>';
            }
            result.innerHTML = html;
            /* Refresh records table */
            if (data.records) { pdnsPremiumRebuildTable(data.records); }
          } else {
            result.innerHTML = '<div class="alert alert-danger"><i class="fa fa-times-circle"></i> '
                             + esc(data.error || 'Import failed.') + '</div>';
          }
        })
        .catch(function(err) {
          btn.disabled = false; spinner.style.display = 'none';
          pdnsPremiumAlert('danger', 'Import failed: ' + err.message);
        });
    });
  }

  function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }
}());
</script>
