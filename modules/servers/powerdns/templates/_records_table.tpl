{* Partial: DNS records table.
   Included by clientarea.tpl.
   Variables: $records (array of flat record hashes)
*}
{if $records}
  <div class="table-responsive">
    <table class="table table-striped table-hover table-condensed">
      <thead>
        <tr>
          <th>Name</th>
          <th>Type</th>
          <th>TTL</th>
          <th>Content</th>
          <th class="text-center">Action</th>
        </tr>
      </thead>
      <tbody>
        {foreach from=$records item=rec}
          <tr>
            <td><code>{$rec.name|escape}</code></td>
            <td>
              <span class="label label-{if $rec.type == 'A'}primary{elseif $rec.type == 'AAAA'}info{elseif $rec.type == 'MX'}warning{elseif $rec.type == 'TXT'}default{else}danger{/if}">
                {$rec.type|escape}
              </span>
            </td>
            <td>{$rec.ttl|escape}</td>
            <td><code class="pdns-content-wrap">{$rec.content|escape}</code></td>
            <td class="text-center">
              {* AJAX-capable delete button; JS intercepts click. *}
              {* Falls back to inline form when JS is unavailable. *}
              <button type="button"
                      class="btn btn-xs btn-danger pdns-delete-btn"
                      data-name="{$rec.name|escape}"
                      data-type="{$rec.type|escape}"
                      data-content="{$rec.content|escape}">
                <i class="fa fa-trash"></i> Delete
              </button>
              {* No-JS fallback form (hidden when JS runs) *}
              <noscript>
                <form method="post" action="" style="display:inline;"
                      onsubmit="return confirm('Delete this record?');">
                  {csrf_token}
                  <input type="hidden" name="powerdns_action"  value="delete_record">
                  <input type="hidden" name="record_type"      value="{$rec.type|escape}">
                  <input type="hidden" name="record_name"      value="{$rec.name|escape}">
                  <input type="hidden" name="record_content"   value="{$rec.content|escape}">
                  <button type="submit" class="btn btn-xs btn-danger">
                    <i class="fa fa-trash"></i> Delete
                  </button>
                </form>
              </noscript>
            </td>
          </tr>
        {/foreach}
      </tbody>
    </table>
  </div>
{else}
  <p class="text-muted" id="pdns-empty-msg">
    <em>No DNS records found for this zone. Add your first record below.</em>
  </p>
{/if}
