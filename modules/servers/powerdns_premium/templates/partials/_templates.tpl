{* Quick-apply DNS templates *}
<div class="row" id="pdns-templates">
  {foreach from=$templates item=tpl}
  <div class="col-sm-4" style="margin-bottom:14px;">
    <div class="panel panel-default" style="margin-bottom:0;">
      <div class="panel-body" style="padding:12px;">
        <h5 style="margin:0 0 6px;">
          <i class="fa {$tpl.icon|escape}"></i> {$tpl.name|escape}
        </h5>
        <p class="text-muted" style="font-size:12px;margin:0 0 8px;">{$tpl.description|escape}</p>
        <p style="font-size:11px;margin:0 0 8px;">
          <strong>Affects:</strong>
          {foreach from=$tpl.affects item=t}
            <span class="label label-default">{$t|escape}</span>
          {/foreach}
        </p>
        {if $licensed}
        <button class="btn btn-xs btn-warning pdns-apply-template-btn"
                data-id="{$tpl.id|escape}"
                data-name="{$tpl.name|escape}"
                data-warning="{$tpl.warning|escape}">
          <i class="fa fa-bolt"></i> Apply
        </button>
        {else}
        <button class="btn btn-xs btn-default" disabled>License required</button>
        {/if}
      </div>
    </div>
  </div>
  {/foreach}
</div>
<div id="pdns-template-spinner" style="display:none;text-align:center;padding:20px;">
  <i class="fa fa-spinner fa-spin fa-2x"></i><br><small>Applying template&hellip;</small>
</div>
