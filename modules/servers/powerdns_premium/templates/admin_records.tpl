{*
  PowerDNS Premium – Admin Area Records View
  Rendered by powerdns_premium_AdminArea()
*}
<div class="pdns-admin-panel">
  <h2><i class="fa fa-globe"></i> PowerDNS Premium &mdash; Zone: <strong>{$zone|escape}</strong></h2>
  <p class="text-muted">Service ID: {$serviceId|escape}</p>

  <div id="pdns-admin-alert" style="display:none;"></div>

  <div class="row">
    <div class="col-sm-12">
      <div class="panel panel-default">
        <div class="panel-heading">
          <strong>DNS Records</strong>
          <span class="pull-right">
            <a href="javascript:void(0)" id="pdns-admin-refresh" class="btn btn-xs btn-default">
              <i class="fa fa-refresh"></i> Refresh
            </a>
          </span>
        </div>
        <div class="panel-body">
          <div id="pdns-admin-records">
            <p class="text-muted"><i class="fa fa-spinner fa-spin"></i> Loading records&hellip;</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
