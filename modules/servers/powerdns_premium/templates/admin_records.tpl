{*
  PowerDNS Premium – Admin Area Records View
  Rendered by powerdns_premium_AdminArea()
*}
<div class="pdns-admin-panel">
  <h2><i class="fa fa-globe"></i> PowerDNS Premium &mdash; Zone: <strong>{$zone|escape}</strong></h2>
  <p class="text-muted">Service ID: {$serviceId|escape}</p>

  {if $error}
    <div class="alert alert-danger">
      <i class="fa fa-exclamation-circle"></i> {$error|escape}
    </div>
  {/if}

  <div class="panel panel-default">
    <div class="panel-heading">
      <strong>DNS Records</strong>
    </div>
    <div class="panel-body">
      {if $records}
        <div class="table-responsive">
          <table class="table table-striped table-condensed">
            <thead>
              <tr>
                <th>Name</th>
                <th>Type</th>
                <th>TTL</th>
                <th>Content</th>
              </tr>
            </thead>
            <tbody>
              {foreach from=$records item=rec}
                <tr>
                  <td><code>{$rec.name|escape}</code></td>
                  <td>{$rec.type|escape}</td>
                  <td>{$rec.ttl|escape}</td>
                  <td><code>{$rec.content|escape}</code></td>
                </tr>
              {/foreach}
            </tbody>
          </table>
        </div>
      {else}
        <p class="text-muted"><em>No active DNS records found for this zone.</em></p>
      {/if}
    </div>
  </div>
</div>
