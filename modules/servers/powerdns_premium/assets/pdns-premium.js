/**
 * PowerDNS Premium – Client Area JavaScript Bundle
 *
 * Handles:
 *   - Record type field toggling (add form)
 *   - Inline record editing
 *   - AJAX add / edit / delete
 *   - Template application
 *   - Global alert helper
 *   - Table rebuild helper (after import / template apply)
 */

(function () {
  'use strict';

  // ── Shared state ──────────────────────────────────────────────────────────
  var serviceId  = '';
  var zone       = '';
  var defaultTTL = 300;
  var licensed   = false;
  var token      = '';

  document.addEventListener('DOMContentLoaded', function () {
    serviceId  = val('pdns-service-id');
    zone       = val('pdns-zone');
    defaultTTL = parseInt(val('pdns-default-ttl'), 10) || 300;
    licensed   = val('pdns-licensed') === '1';
    token      = val('pdns-token');

    initAddForm();
    initRecordsTable();
    initTemplates();
  });

  // ── Helpers ───────────────────────────────────────────────────────────────

  function val(id) {
    var el = document.getElementById(id);
    return el ? el.value : '';
  }

  function esc(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function badgeClass(type) {
    var map = {
      A:'primary', AAAA:'info', CNAME:'primary', MX:'warning',
      TXT:'default', SRV:'danger', CAA:'warning', NS:'success',
      ALIAS:'primary', PTR:'default'
    };
    return map[type] || 'default';
  }

  /** Global alert bar (replaces server-side flash for AJAX actions) */
  window.pdnsPremiumAlert = function (type, message) {
    var el = document.getElementById('pdns-alert');
    if (!el) return;
    el.className = 'alert alert-' + type + ' alert-dismissible';
    el.innerHTML = '<button type="button" class="close" onclick="this.parentNode.style.display=\'none\'">'
                 + '<span>&times;</span></button>'
                 + '<i class="fa fa-' + (type === 'success' ? 'check' : 'exclamation') + '-circle"></i> '
                 + esc(message);
    el.style.display = '';
    el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  };

  /** Rebuild the records table from a flat records array (after import/template) */
  window.pdnsPremiumRebuildTable = function (records) {
    var wrapper = document.getElementById('pdns-records-wrapper');
    if (!wrapper) return;

    if (!records || !records.length) {
      wrapper.innerHTML = '<p class="text-muted" id="pdns-empty-msg"><em>No DNS records found.</em></p>';
      return;
    }

    var rows = records.map(function (rec) {
      return '<tr data-name="' + esc(rec.name) + '" data-type="' + esc(rec.type)
           + '" data-content="' + esc(rec.content) + '" data-ttl="' + esc(rec.ttl) + '">'
           + '<td class="pdns-view-col"><code>' + esc(rec.name) + '</code></td>'
           + '<td class="pdns-view-col"><span class="pdns-badge-' + esc(rec.type) + '">' + esc(rec.type) + '</span></td>'
           + '<td class="pdns-view-col pdns-ttl-view">' + esc(rec.ttl) + '</td>'
           + '<td class="pdns-view-col"><code class="pdns-content-wrap">' + esc(rec.content) + '</code></td>'
           + '<td class="pdns-view-col text-center">'
           + (licensed ? '<button class="btn btn-xs btn-primary pdns-edit-btn" title="Edit"><i class="fa fa-pencil"></i></button> ' : '')
           + '<button class="btn btn-xs btn-danger pdns-delete-btn"'
           + ' data-name="' + esc(rec.name) + '" data-type="' + esc(rec.type) + '" data-content="' + esc(rec.content) + '"'
           + ' title="Delete"><i class="fa fa-trash"></i></button></td>'
           /* edit cols */
           + '<td colspan="4" class="pdns-edit-col" style="display:none;">'
           + '<div class="pdns-edit-row" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">'
           + '<input type="text" class="form-control pdns-edit-content" value="' + esc(rec.content) + '" style="flex:1;min-width:200px;">'
           + '<input type="number" class="form-control pdns-edit-ttl" value="' + esc(rec.ttl) + '" style="width:90px;" min="60" max="86400">'
           + '<button class="btn btn-xs btn-success pdns-edit-save-btn"><i class="fa fa-check"></i> Save</button>'
           + '<button class="btn btn-xs btn-default pdns-edit-cancel-btn"><i class="fa fa-times"></i> Cancel</button>'
           + '<span class="pdns-spinner"><i class="fa fa-spinner fa-spin"></i></span></div></td>'
           + '<td class="pdns-edit-col" style="display:none;"></td>'
           + '</tr>';
    }).join('');

    wrapper.innerHTML = '<div class="table-responsive">'
      + '<table class="table table-striped table-hover table-condensed" id="pdns-records-table">'
      + '<thead><tr><th>Name</th><th>Type</th><th>TTL</th><th>Content</th>'
      + '<th class="text-center" style="width:130px;">Actions</th></tr></thead>'
      + '<tbody>' + rows + '</tbody></table></div>';
  };

  // ── Add form ──────────────────────────────────────────────────────────────

  /** Show/hide field groups based on selected record type */
  window.pdnsShowFields = function (type) {
    document.querySelectorAll('.pdns-type-fields').forEach(function (el) {
      el.style.display = 'none';
    });
    // ALIAS, PTR, NS share CNAME layout
    var target = (['ALIAS', 'PTR', 'NS'].indexOf(type) !== -1) ? 'CNAME' : type;
    var el = document.getElementById('pdns-fields-' + target);
    if (el) el.style.display = '';
  };

  function initAddForm() {
    var form    = document.getElementById('pdns-add-form');
    var typeEl  = document.getElementById('pdns-record-type');
    if (!form) return;

    // Wire onchange in case inline handler is missing
    if (typeEl) {
      typeEl.addEventListener('change', function () { pdnsShowFields(this.value); });
    }

    if (!window.fetch) return;

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var type = typeEl ? typeEl.value : 'A';

      // Basic SRV port check
      if (type === 'SRV') {
        var portEl = form.querySelector('[name="srv_port"]');
        if (!portEl || parseInt(portEl.value, 10) < 1) {
          pdnsPremiumAlert('danger', 'Please enter a valid SRV port (1–65535).');
          return;
        }
      }

      // Require a visible value field to be filled
      var valueFields = form.querySelectorAll('[name="record_value"]');
      var hasVal = false;
      valueFields.forEach(function (f) {
        if (f.offsetParent !== null && f.value.trim() !== '') hasVal = true;
      });
      if (!hasVal) {
        pdnsPremiumAlert('danger', 'Please enter a value for the record.');
        return;
      }

      var btn     = document.getElementById('pdns-add-btn');
      var spinner = document.getElementById('pdns-add-spinner');
      btn.disabled = true;
      spinner.style.display = '';

      var fd = new FormData(form);
      fd.append('pdns_p_ajax', '1'); // flag as AJAX (not in static HTML)

      fetch(window.location.href, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          btn.disabled = false;
          spinner.style.display = 'none';
          if (data.success) {
            pdnsPremiumAlert('success', 'Record added successfully.');
            appendRecord(data.record);
            form.reset();
            pdnsShowFields('A');
          } else {
            pdnsPremiumAlert('danger', data.error || 'Unknown error.');
          }
        })
        .catch(function (err) {
          btn.disabled = false;
          spinner.style.display = 'none';
          pdnsPremiumAlert('danger', 'Request failed: ' + err.message);
        });
    });
  }

  /** Append a single new record row to the table (optimistic UI) */
  function appendRecord(rec) {
    var tbody = document.querySelector('#pdns-records-table tbody');
    if (!tbody) {
      // Table doesn't exist yet (was empty) – rebuild
      pdnsPremiumRebuildTable([rec]);
      return;
    }

    var tr = document.createElement('tr');
    tr.setAttribute('data-name',    rec.name);
    tr.setAttribute('data-type',    rec.type);
    tr.setAttribute('data-content', rec.content);
    tr.setAttribute('data-ttl',     rec.ttl);
    tr.innerHTML =
        '<td class="pdns-view-col"><code>' + esc(rec.name) + '</code></td>'
      + '<td class="pdns-view-col"><span class="pdns-badge-' + esc(rec.type) + '">' + esc(rec.type) + '</span></td>'
      + '<td class="pdns-view-col pdns-ttl-view">' + esc(rec.ttl) + '</td>'
      + '<td class="pdns-view-col"><code class="pdns-content-wrap">' + esc(rec.content) + '</code></td>'
      + '<td class="pdns-view-col text-center">'
      + (licensed ? '<button class="btn btn-xs btn-primary pdns-edit-btn" title="Edit"><i class="fa fa-pencil"></i></button> ' : '')
      + '<button class="btn btn-xs btn-danger pdns-delete-btn"'
      + ' data-name="' + esc(rec.name) + '" data-type="' + esc(rec.type) + '" data-content="' + esc(rec.content) + '"'
      + ' title="Delete"><i class="fa fa-trash"></i></button></td>'
      + '<td colspan="4" class="pdns-edit-col" style="display:none;">'
      + '<div class="pdns-edit-row" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">'
      + '<input type="text" class="form-control pdns-edit-content" value="' + esc(rec.content) + '" style="flex:1;min-width:200px;">'
      + '<input type="number" class="form-control pdns-edit-ttl" value="' + esc(rec.ttl) + '" style="width:90px;" min="60">'
      + '<button class="btn btn-xs btn-success pdns-edit-save-btn"><i class="fa fa-check"></i> Save</button>'
      + '<button class="btn btn-xs btn-default pdns-edit-cancel-btn"><i class="fa fa-times"></i> Cancel</button>'
      + '<span class="pdns-spinner"><i class="fa fa-spinner fa-spin"></i></span>'
      + '</div></td>'
      + '<td class="pdns-edit-col" style="display:none;"></td>';
    tbody.appendChild(tr);

    var empty = document.getElementById('pdns-empty-msg');
    if (empty) empty.style.display = 'none';
  }

  // ── Records table (edit + delete) ────────────────────────────────────────

  function initRecordsTable() {
    var wrapper = document.getElementById('pdns-records-wrapper');
    if (!wrapper || !window.fetch) return;

    wrapper.addEventListener('click', function (e) {

      // ── Edit button ───────────────────────────────────────────
      var editBtn = e.target.closest('.pdns-edit-btn');
      if (editBtn) {
        var row     = editBtn.closest('tr');
        var viewCols = row.querySelectorAll('.pdns-view-col');
        var editCols = row.querySelectorAll('.pdns-edit-col');
        viewCols.forEach(function (c) { c.style.display = 'none'; });
        editCols.forEach(function (c) { c.style.display = ''; });
        return;
      }

      // ── Edit cancel ───────────────────────────────────────────
      var cancelBtn = e.target.closest('.pdns-edit-cancel-btn');
      if (cancelBtn) {
        var row     = cancelBtn.closest('tr');
        var viewCols = row.querySelectorAll('.pdns-view-col');
        var editCols = row.querySelectorAll('.pdns-edit-col');
        editCols.forEach(function (c) { c.style.display = 'none'; });
        viewCols.forEach(function (c) { c.style.display = ''; });
        return;
      }

      // ── Edit save ─────────────────────────────────────────────
      var saveBtn = e.target.closest('.pdns-edit-save-btn');
      if (saveBtn) {
        var row        = saveBtn.closest('tr');
        var name       = row.getAttribute('data-name');
        var type       = row.getAttribute('data-type');
        var oldContent = row.getAttribute('data-content');
        var newContent = row.querySelector('.pdns-edit-content').value.trim();
        var newTTL     = parseInt(row.querySelector('.pdns-edit-ttl').value, 10) || defaultTTL;
        var spinner    = row.querySelector('.pdns-spinner');

        if (!newContent) { pdnsPremiumAlert('danger', 'Content cannot be empty.'); return; }

        saveBtn.disabled = true;
        spinner.style.display = '';

        var fd = new FormData();
        fd.append('pdns_action',  'edit_record');
        fd.append('pdns_p_ajax',  '1');
        fd.append('service_id',   serviceId);
        fd.append('token',        token);
        fd.append('record_type',  type);
        fd.append('record_name',  name);
        fd.append('old_content',  oldContent);
        fd.append('record_value', newContent);
        fd.append('record_ttl',   newTTL);

        fetch(window.location.href, { method: 'POST', body: fd, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            saveBtn.disabled = false;
            spinner.style.display = 'none';
            if (data.success) {
              // Update row data attrs
              row.setAttribute('data-content', data.newContent);
              row.setAttribute('data-ttl',     data.newTTL);
              // Update view cells
              row.querySelector('.pdns-content-wrap').textContent    = data.newContent;
              row.querySelector('.pdns-ttl-view').textContent         = data.newTTL;
              // Update delete button
              var delBtn = row.querySelector('.pdns-delete-btn');
              if (delBtn) delBtn.setAttribute('data-content', data.newContent);
              // Update edit input default
              row.querySelector('.pdns-edit-content').value = data.newContent;
              // Switch back to view mode
              row.querySelectorAll('.pdns-edit-col').forEach(function (c) { c.style.display = 'none'; });
              row.querySelectorAll('.pdns-view-col').forEach(function (c) { c.style.display = ''; });
              pdnsPremiumAlert('success', 'Record updated.');
            } else {
              pdnsPremiumAlert('danger', data.error || 'Update failed.');
            }
          })
          .catch(function (err) {
            saveBtn.disabled = false;
            spinner.style.display = 'none';
            pdnsPremiumAlert('danger', 'Request failed: ' + err.message);
          });
        return;
      }

      // ── Delete ────────────────────────────────────────────────
      var delBtn = e.target.closest('.pdns-delete-btn');
      if (delBtn) {
        var name    = delBtn.getAttribute('data-name');
        var type    = delBtn.getAttribute('data-type');
        var content = delBtn.getAttribute('data-content');
        var row     = delBtn.closest('tr');

        if (!confirm('Delete ' + type + ' record for ' + name + '?')) return;

        delBtn.disabled = true;
        delBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i>';

        var fd = new FormData();
        fd.append('pdns_action',    'delete_record');
        fd.append('pdns_p_ajax',    '1');
        fd.append('service_id',     serviceId);
        fd.append('token',          token);
        fd.append('record_type',    type);
        fd.append('record_name',    name);
        fd.append('record_content', content);

        fetch(window.location.href, { method: 'POST', body: fd, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (data.success) {
              row.remove();
              pdnsPremiumAlert('success', 'Record deleted.');
              var tbody = document.querySelector('#pdns-records-table tbody');
              if (tbody && !tbody.querySelector('tr')) {
                var wrapper = document.getElementById('pdns-records-wrapper');
                if (wrapper) {
                  wrapper.innerHTML = '<p class="text-muted" id="pdns-empty-msg"><em>No DNS records found.</em></p>';
                }
              }
            } else {
              delBtn.disabled = false;
              delBtn.innerHTML = '<i class="fa fa-trash"></i>';
              pdnsPremiumAlert('danger', data.error || 'Delete failed.');
            }
          })
          .catch(function (err) {
            delBtn.disabled = false;
            delBtn.innerHTML = '<i class="fa fa-trash"></i>';
            pdnsPremiumAlert('danger', 'Request failed: ' + err.message);
          });
      }
    });
  }

  // ── Templates ─────────────────────────────────────────────────────────────

  function initTemplates() {
    var container = document.getElementById('pdns-templates');
    var spinner   = document.getElementById('pdns-template-spinner');
    if (!container || !window.fetch) return;

    container.addEventListener('click', function (e) {
      var btn = e.target.closest('.pdns-apply-template-btn');
      if (!btn) return;

      var id      = btn.getAttribute('data-id');
      var name    = btn.getAttribute('data-name');
      var warning = btn.getAttribute('data-warning');

      var msg = 'Apply "' + name + '" template?\n\n' + warning;
      if (!confirm(msg)) return;

      container.style.opacity = '0.4';
      spinner.style.display = '';

      var fd = new FormData();
      fd.append('pdns_action', 'apply_template');
      fd.append('pdns_p_ajax', '1');
      fd.append('service_id',  serviceId);
      fd.append('token',       token);
      fd.append('template_id', id);

      fetch(window.location.href, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          container.style.opacity = '';
          spinner.style.display = 'none';
          if (data.success) {
            pdnsPremiumAlert('success', '"' + name + '" template applied.');
            if (data.records) pdnsPremiumRebuildTable(data.records);
          } else {
            pdnsPremiumAlert('danger', data.error || 'Template apply failed.');
          }
        })
        .catch(function (err) {
          container.style.opacity = '';
          spinner.style.display = 'none';
          pdnsPremiumAlert('danger', 'Request failed: ' + err.message);
        });
    });
  }

}());
