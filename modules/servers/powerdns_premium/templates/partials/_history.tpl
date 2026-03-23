{* Audit history tab *}
<h4 style="margin-top:18px;">Change History
  <small class="text-muted">{$auditTotal} total entries</small>
</h4>

{if $auditEntries}
<div class="table-responsive">
  <table class="table table-condensed table-hover" id="pdns-history-table">
    <thead>
      <tr>
        <th style="width:140px;">Date / Time</th>
        <th style="width:90px;">Action</th>
        <th style="width:70px;">Type</th>
        <th>Record Name</th>
        <th>Content</th>
        <th style="width:110px;">IP Address</th>
      </tr>
    </thead>
    <tbody>
      {foreach from=$auditEntries item=entry}
      <tr>
        <td style="font-size:12px;">{$entry.created_at|escape}</td>
        <td>
          {assign var="aclass" value="default"}
          {if $entry.action == 'add'}{assign var="aclass" value="success"}{/if}
          {if $entry.action == 'edit'}{assign var="aclass" value="info"}{/if}
          {if $entry.action == 'delete'}{assign var="aclass" value="danger"}{/if}
          {if $entry.action == 'template'}{assign var="aclass" value="warning"}{/if}
          {if $entry.action == 'import'}{assign var="aclass" value="primary"}{/if}
          <span class="label label-{$aclass}">{$entry.action|escape|capitalize}</span>
        </td>
        <td><span class="pdns-badge-{$entry.record_type|escape}">{$entry.record_type|escape}</span></td>
        <td style="font-size:12px;"><code>{$entry.record_name|escape}</code></td>
        <td style="font-size:12px;word-break:break-all;max-width:240px;">
          <code>{$entry.record_content|truncate:80:"…"|escape}</code>
          {if $entry.note}
            <br><span class="text-muted" style="font-size:11px;">{$entry.note|escape}</span>
          {/if}
        </td>
        <td style="font-size:11px;color:#999;">{$entry.ip_address|escape}</td>
      </tr>
      {/foreach}
    </tbody>
  </table>
</div>

{* Pagination *}
{if $auditTotal > 50}
<div class="text-center">
  <div id="pdns-history-pagination" style="display:inline-flex;gap:6px;margin-top:8px;">
    <button class="btn btn-xs btn-default" id="pdns-history-prev" disabled>
      <i class="fa fa-chevron-left"></i> Prev
    </button>
    <span id="pdns-history-page-info" style="line-height:22px;font-size:12px;">Page 1</span>
    <button class="btn btn-xs btn-default" id="pdns-history-next">
      Next <i class="fa fa-chevron-right"></i>
    </button>
  </div>
</div>
{/if}
{else}
<p class="text-muted"><em>No changes recorded yet. All DNS record changes will appear here.</em></p>
{/if}

<script>
(function() {
  var page    = 1;
  var perPage = 20;
  var total   = {$auditTotal|intval};
  var sid     = document.getElementById('pdns-service-id').value;
  var token   = document.getElementById('pdns-token').value;

  function loadPage(p) {
    var fd = new FormData();
    fd.append('pdns_action', 'get_audit');
    fd.append('pdns_p_ajax', '1');
    fd.append('service_id',  sid);
    fd.append('token',       token);
    fd.append('page',        p);

    fetch(window.location.href, { method:'POST', body:fd, credentials:'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (!data.success) return;
        var tbody = document.querySelector('#pdns-history-table tbody');
        if (!tbody) return;
        tbody.innerHTML = '';
        data.entries.forEach(function(e) {
          var classes = {add:'success', edit:'info', delete:'danger', template:'warning', import:'primary'};
          var cls = classes[e.action] || 'default';
          tbody.innerHTML += '<tr>'
            + '<td style="font-size:12px;">' + esc(e.created_at) + '</td>'
            + '<td><span class="label label-' + cls + '">' + esc(e.action) + '</span></td>'
            + '<td><span class="pdns-badge-' + esc(e.record_type) + '">' + esc(e.record_type) + '</span></td>'
            + '<td style="font-size:12px;"><code>' + esc(e.record_name) + '</code></td>'
            + '<td style="font-size:12px;word-break:break-all;max-width:240px;"><code>' + esc(e.record_content.substring(0,80)) + '</code>'
            + (e.note ? '<br><span class="text-muted" style="font-size:11px;">' + esc(e.note) + '</span>' : '')
            + '</td>'
            + '<td style="font-size:11px;color:#999;">' + esc(e.ip_address) + '</td>'
            + '</tr>';
        });
        page = data.page;
        var info = document.getElementById('pdns-history-page-info');
        if (info) info.textContent = 'Page ' + page + ' of ' + Math.ceil(total / perPage);
        var prev = document.getElementById('pdns-history-prev');
        var next = document.getElementById('pdns-history-next');
        if (prev) prev.disabled = (page <= 1);
        if (next) next.disabled = (page * perPage >= total);
      });
  }

  var prev = document.getElementById('pdns-history-prev');
  var next = document.getElementById('pdns-history-next');
  if (prev) prev.addEventListener('click', function() { if (page > 1) loadPage(page - 1); });
  if (next) next.addEventListener('click', function() { loadPage(page + 1); });

  function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }
}());
</script>
