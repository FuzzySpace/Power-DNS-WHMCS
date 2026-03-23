{* Records table partial – supports inline edit *}
{if $records}
<div class="table-responsive">
  <table class="table table-striped table-hover table-condensed" id="pdns-records-table">
    <thead>
      <tr>
        <th>Name</th><th>Type</th><th>TTL</th><th>Content</th>
        <th class="text-center" style="width:130px;">Actions</th>
      </tr>
    </thead>
    <tbody>
      {foreach from=$records item=rec}
      <tr data-name="{$rec.name|escape}" data-type="{$rec.type|escape}" data-content="{$rec.content|escape}" data-ttl="{$rec.ttl|escape}">
        {* View mode *}
        <td class="pdns-view-col"><code>{$rec.name|escape}</code></td>
        <td class="pdns-view-col"><span class="pdns-badge-{$rec.type|escape}">{$rec.type|escape}</span></td>
        <td class="pdns-view-col pdns-ttl-view">{$rec.ttl|escape}</td>
        <td class="pdns-view-col"><code class="pdns-content-wrap">{$rec.content|escape}</code></td>
        <td class="pdns-view-col text-center">
          {if $licensed}
          <button class="btn btn-xs btn-primary pdns-edit-btn" title="Edit"><i class="fa fa-pencil"></i></button>
          {/if}
          <button class="btn btn-xs btn-danger pdns-delete-btn"
                  data-name="{$rec.name|escape}"
                  data-type="{$rec.type|escape}"
                  data-content="{$rec.content|escape}"
                  title="Delete"><i class="fa fa-trash"></i></button>
        </td>
        {* Edit mode (hidden by default) *}
        <td colspan="4" class="pdns-edit-col" style="display:none;">
          <div class="pdns-edit-row" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <input type="text"   class="form-control pdns-edit-content" value="{$rec.content|escape}" style="flex:1;min-width:200px;">
            <input type="number" class="form-control pdns-edit-ttl"     value="{$rec.ttl|escape}"     style="width:90px;" min="60" max="86400">
            <button class="btn btn-xs btn-success pdns-edit-save-btn"><i class="fa fa-check"></i> Save</button>
            <button class="btn btn-xs btn-default pdns-edit-cancel-btn"><i class="fa fa-times"></i> Cancel</button>
            <span class="pdns-spinner"><i class="fa fa-spinner fa-spin"></i></span>
          </div>
        </td>
        <td class="pdns-edit-col" style="display:none;"></td>
      </tr>
      {/foreach}
    </tbody>
  </table>
</div>
{else}
<p class="text-muted" id="pdns-empty-msg"><em>No DNS records found. Add your first record below.</em></p>
{/if}
