<?php

/**
 * PowerDNS WHMCS Provisioning Module – Premium Edition
 *
 * WHMCS Marketplace product. Extend the base PowerDNS module with:
 *   - 10 record types (A, AAAA, CNAME, MX, TXT, SRV, CAA, NS, ALIAS, PTR)
 *   - Inline record editing
 *   - DNSSEC management tab
 *   - DNS propagation checker tab
 *   - Audit log / history tab
 *   - Record templates (Google Workspace, M365, Zoho, GitHub Pages, etc.)
 *   - CSV bulk import + BIND / CSV export
 *   - SOA editor (admin only)
 *   - Record quotas per product
 *   - License validation
 *   - Email notifications on provisioning
 *
 * Installation:
 *   1. Upload modules/servers/powerdns_premium/ to your WHMCS installation.
 *   2. Navigate to Admin → Addon Modules → PowerDNS Premium → Activate,
 *      then click "Run Installer" to create the required database tables.
 *      (Or hit <whmcs>/modules/servers/powerdns_premium/install.php once.)
 *   3. Add a Server (type = PowerDNS Premium) with API URL + key.
 *   4. Create a Product, set module = PowerDNS Premium, configure options.
 *   5. Enter your license key in config option 8.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

$moduleDir = __DIR__;
require_once $moduleDir . '/lib/PowerDNSAPI.php';
require_once $moduleDir . '/lib/LicenseManager.php';
require_once $moduleDir . '/lib/AuditLog.php';
require_once $moduleDir . '/lib/PropagationChecker.php';
require_once $moduleDir . '/lib/ZoneIO.php';
require_once $moduleDir . '/lib/RecordTemplates.php';

// =============================================================================
// Module metadata
// =============================================================================

function powerdns_premium_MetaData()
{
    return [
        'DisplayName'              => 'PowerDNS Premium DNS',
        'APIVersion'               => '1.1',
        'RequiresServer'           => true,
        'DefaultNonSSLPort'        => '8081',
        'DefaultSSLPort'           => '8081',
        'ServiceSingleSignOnLabel' => false,
        'ListClientsWithStatus'    => ['Active', 'Suspended'],
    ];
}

// =============================================================================
// Config options
// =============================================================================

function powerdns_premium_ConfigOptions()
{
    return [
        'Nameserver 1' => [
            'Type'        => 'text',
            'Size'        => 50,
            'Default'     => 'ns1.example.com',
            'Description' => 'Primary nameserver (used in SOA and NS records)',
        ],
        'Nameserver 2' => [
            'Type'        => 'text',
            'Size'        => 50,
            'Default'     => 'ns2.example.com',
            'Description' => 'Secondary nameserver',
        ],
        'Hostmaster Email' => [
            'Type'        => 'text',
            'Size'        => 50,
            'Default'     => 'hostmaster.example.com',
            'Description' => 'Hostmaster e-mail in DNS format (@ → dot)',
        ],
        'Default TTL' => [
            'Type'        => 'text',
            'Size'        => 10,
            'Default'     => '300',
            'Description' => 'Default TTL (seconds) for new records',
        ],
        'PowerDNS Server ID' => [
            'Type'        => 'text',
            'Size'        => 30,
            'Default'     => 'localhost',
            'Description' => 'PowerDNS server identifier (usually "localhost")',
        ],
        'Max Records Per Zone' => [
            'Type'        => 'text',
            'Size'        => 10,
            'Default'     => '0',
            'Description' => 'Maximum DNS records per zone (0 = unlimited)',
        ],
        'Allow Client DNSSEC' => [
            'Type'        => 'yesno',
            'Description' => 'Allow clients to enable/disable DNSSEC on their zone',
        ],
        'License Key' => [
            'Type'        => 'text',
            'Size'        => 80,
            'Default'     => '',
            'Description' => 'Your PowerDNS Premium license key',
        ],
    ];
}

// =============================================================================
// Internal helpers
// =============================================================================

function _pdns_p_getClient(array $params)
{
    $host     = $params['serverhostname'] ?: $params['serverip'];
    $port     = $params['serverport'] ?: 8081;
    $apiKey   = $params['serverpassword'];
    $serverId = trim($params['configoption5'] ?: 'localhost');
    $scheme   = ($params['serversecure'] === 'on') ? 'https' : 'http';
    return new PowerDNSAPI("{$scheme}://{$host}:{$port}", $apiKey, $serverId);
}

function _pdns_p_zone(array $params)
{
    return _pdns_p_toAsciiDomain($params['domain']);
}

/**
 * Normalize a domain name: lowercase, trim, and convert to punycode (ACE)
 * so that IDN domains like münchen.de become xn--mnchen-3ya.de.
 * Falls back to plain strtolower when the intl extension is unavailable.
 */
function _pdns_p_toAsciiDomain($domain)
{
    $domain = strtolower(trim($domain));
    if (extension_loaded('intl') && function_exists('idn_to_ascii')) {
        $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if ($ascii !== false) {
            return $ascii;
        }
    }
    return $domain;
}

function _pdns_p_ns(array $params)
{
    $ns = array_filter([
        rtrim(strtolower(trim($params['configoption1'] ?? '')), '.') . '.',
        rtrim(strtolower(trim($params['configoption2'] ?? '')), '.') . '.',
    ]);
    return $ns ?: ['ns1.example.com.', 'ns2.example.com.'];
}

function _pdns_p_maxRecords(array $params)
{
    return (int) ($params['configoption6'] ?? 0);
}

function _pdns_p_allowDNSSEC(array $params)
{
    return !empty($params['configoption7']);
}

function _pdns_p_license(array $params)
{
    $key     = trim($params['configoption8'] ?? '');
    $whmcsUrl = method_exists('\App', 'getSystemUrl') ? \App::getSystemUrl() : '';
    return new LicenseManager($key, $whmcsUrl);
}

/**
 * Build DNS record content string from POST data.
 */
function _pdns_p_buildContent($type, array $post)
{
    switch (strtoupper($type)) {
        case 'A':
            $ip = trim($post['record_value'] ?? '');
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new InvalidArgumentException("Invalid IPv4 address: {$ip}");
            }
            return $ip;

        case 'AAAA':
            $ip = trim($post['record_value'] ?? '');
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new InvalidArgumentException("Invalid IPv6 address: {$ip}");
            }
            return $ip;

        case 'CNAME':
        case 'ALIAS':
        case 'PTR':
            $target = rtrim(trim($post['record_value'] ?? ''), '.') . '.';
            if (strlen($target) < 3) {
                throw new InvalidArgumentException("Target hostname is required.");
            }
            return $target;

        case 'NS':
            $target = rtrim(trim($post['record_value'] ?? ''), '.') . '.';
            if (strlen($target) < 3) {
                throw new InvalidArgumentException("Nameserver hostname is required.");
            }
            return $target;

        case 'MX':
            $priority = max(0, (int) ($post['record_priority'] ?? 10));
            $target   = rtrim(trim($post['record_value'] ?? ''), '.') . '.';
            if (strlen(rtrim($target, '.')) < 1) {
                throw new InvalidArgumentException("MX mail server is required.");
            }
            return "{$priority} {$target}";

        case 'TXT':
            $value = trim($post['record_value'] ?? '');
            if (empty($value)) {
                throw new InvalidArgumentException("TXT content is required.");
            }
            if (substr($value, 0, 1) !== '"') {
                $value = '"' . str_replace('"', '\\"', $value) . '"';
            }
            return $value;

        case 'SRV':
            $priority = max(0,   (int) ($post['srv_priority'] ?? 10));
            $weight   = max(0,   (int) ($post['srv_weight']   ?? 0));
            $port     = (int) ($post['srv_port'] ?? 0);
            $target   = rtrim(trim($post['record_value'] ?? ''), '.') . '.';
            if ($port < 1 || $port > 65535) {
                throw new InvalidArgumentException("SRV port must be 1–65535.");
            }
            return "{$priority} {$weight} {$port} {$target}";

        case 'CAA':
            $flag  = (int) ($post['caa_flag']  ?? 0);
            $tag   = trim($post['caa_tag']     ?? 'issue');
            $value = trim($post['record_value'] ?? '');
            if (!in_array($tag, ['issue', 'issuewild', 'iodef'], true)) {
                throw new InvalidArgumentException("CAA tag must be issue, issuewild, or iodef.");
            }
            if (empty($value)) {
                throw new InvalidArgumentException("CAA value is required.");
            }
            // value should be quoted
            if (substr($value, 0, 1) !== '"') {
                $value = '"' . $value . '"';
            }
            return "{$flag} {$tag} {$value}";

        default:
            throw new InvalidArgumentException("Unsupported record type: {$type}");
    }
}

// =============================================================================
// Provisioning hooks
// =============================================================================

function powerdns_premium_CreateAccount(array $params)
{
    try {
        $api  = _pdns_p_getClient($params);
        $zone = _pdns_p_zone($params);
        $ns   = _pdns_p_ns($params);
        $hm   = trim($params['configoption3'] ?: 'hostmaster.example.com');

        if (!$api->zoneExists($zone)) {
            $api->createZone($zone, $ns, $hm);
        }

        // Send welcome email if the WHMCS email template exists
        _pdns_p_sendEmail('powerdns_premium_zone_created', $params);

        return 'success';
    } catch (Exception $e) {
        logActivity('PowerDNS Premium module error [' . $params['domain'] . ']: ' . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

function powerdns_premium_SuspendAccount(array $params)
{
    try {
        _pdns_p_getClient($params)->disableZoneRecords(_pdns_p_zone($params));
        return 'success';
    } catch (Exception $e) {
        logActivity('PowerDNS Premium module error [' . $params['domain'] . ']: ' . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

function powerdns_premium_UnsuspendAccount(array $params)
{
    try {
        _pdns_p_getClient($params)->enableZoneRecords(_pdns_p_zone($params));
        return 'success';
    } catch (Exception $e) {
        logActivity('PowerDNS Premium module error [' . $params['domain'] . ']: ' . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

function powerdns_premium_TerminateAccount(array $params)
{
    try {
        _pdns_p_getClient($params)->deleteZone(_pdns_p_zone($params));
        return 'success';
    } catch (Exception $e) {
        logActivity('PowerDNS Premium module error [' . $params['domain'] . ']: ' . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

// =============================================================================
// Client Area
// =============================================================================

/**
 * Adds "Manage DNS Records" to the service actions panel in the WHMCS
 * client area, giving clients a direct navigation button.
 */
function powerdns_premium_ClientAreaCustomButtonArray()
{
    return [
        'Manage DNS Records' => 'managedns',
    ];
}

function powerdns_premium_ClientArea(array $params)
{
    $zone       = _pdns_p_zone($params);
    $serviceId  = $params['serviceid'];
    $serviceUrl = 'clientarea.php?action=productdetails&id=' . $serviceId;
    $manageUrl  = $serviceUrl . '&view=managedns';

    if ($params['status'] !== 'Active') {
        return [
            'templatefile' => 'inactive',
            'vars'         => ['status' => $params['status']],
        ];
    }

    // License check
    $license    = _pdns_p_license($params);
    $licensed   = $license->isValid();
    $nameservers = _pdns_p_ns($params);

    $api        = _pdns_p_getClient($params);
    $defaultTTL = (int) ($params['configoption4'] ?: 300);
    $maxRecords = _pdns_p_maxRecords($params);
    $allowDNS   = _pdns_p_allowDNSSEC($params);
    $userId     = $params['userid'] ?? ($_SESSION['uid'] ?? 0);
    $error      = '';
    $success    = '';

    // ------------------------------------------------------------------
    // Route: overview vs DNS manager
    // ------------------------------------------------------------------
    $view        = $params['customaction'] ?? ($_GET['view'] ?? '');
    $showManager = ($view === 'managedns')
                || ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pdns_action']));

    if (!$showManager) {
        // ── Overview page ──────────────────────────────────────────────
        $recordCount = 0;
        $zoneError   = '';
        try {
            $rrs = $api->getRecords($zone, PowerDNSAPI::$SUPPORTED_TYPES);
            foreach ($rrs as $rr) {
                $recordCount += count(array_filter($rr['records'], fn($r) => !($r['disabled'] ?? false)));
            }
        } catch (Exception $e) {
            $zoneError = $e->getMessage();
        }
        return [
            'templatefile' => 'overview',
            'vars' => [
                'zone'        => $zone,
                'nameservers' => $nameservers,
                'recordCount' => $recordCount,
                'allowDNSSEC' => $allowDNS,
                'manageUrl'   => $manageUrl,
                'licensed'    => $licensed,
                'error'       => $zoneError,
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Handle POST (non-AJAX fallback)
    // ------------------------------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pdns_action'])) {
        check_token('WHMCS.clientarea');
        $action = $_POST['pdns_action'];

        if (!$licensed && $action !== 'check_propagation') {
            $error = 'Module license is invalid. Record management is disabled.';
        } else {
            try {
                switch ($action) {
                    case 'add_record':
                        if ($maxRecords > 0 && $api->getRecordCount($zone) >= $maxRecords) {
                            throw new RuntimeException("Record quota of {$maxRecords} reached.");
                        }
                        $type    = strtoupper(trim($_POST['record_type']));
                        $name    = trim($_POST['record_name'] ?? '');
                        $ttl     = max(60, (int) ($_POST['record_ttl'] ?? $defaultTTL));
                        $content = _pdns_p_buildContent($type, $_POST);
                        $api->addRecord($zone, $name ?: $zone, $type, $content, $ttl);
                        AuditLog::write($serviceId, $userId, AuditLog::ACTION_ADD, $name ?: $zone, $type, $content, $ttl);
                        $success = 'Record added successfully.';
                        break;

                    case 'edit_record':
                        $type       = strtoupper(trim($_POST['record_type']));
                        $name       = trim($_POST['record_name']);
                        $oldContent = trim($_POST['old_content']);
                        $ttl        = max(60, (int) ($_POST['record_ttl'] ?? $defaultTTL));
                        $newContent = _pdns_p_buildContent($type, $_POST);
                        $api->editRecord($zone, $name, $type, $oldContent, $newContent, $ttl);
                        AuditLog::write($serviceId, $userId, AuditLog::ACTION_EDIT, $name, $type, $newContent, $ttl, "old: {$oldContent}");
                        $success = 'Record updated successfully.';
                        break;

                    case 'delete_record':
                        $type    = strtoupper(trim($_POST['record_type']));
                        $name    = trim($_POST['record_name']);
                        $content = trim($_POST['record_content']);
                        $api->deleteRecord($zone, $name, $type, $content);
                        AuditLog::write($serviceId, $userId, AuditLog::ACTION_DELETE, $name, $type, $content);
                        $success = 'Record deleted.';
                        break;

                    case 'toggle_dnssec':
                        if (!$allowDNS) throw new RuntimeException('DNSSEC management is not enabled for this product.');
                        $enable = !empty($_POST['dnssec_enable']);
                        if ($enable) {
                            $api->enableDNSSEC($zone);
                            AuditLog::write($serviceId, $userId, AuditLog::ACTION_DNSSEC, $zone, 'DNSSEC', 'enabled');
                            $success = 'DNSSEC enabled. Retrieve your DS records below.';
                        } else {
                            $api->disableDNSSEC($zone);
                            AuditLog::write($serviceId, $userId, AuditLog::ACTION_DNSSEC, $zone, 'DNSSEC', 'disabled');
                            $success = 'DNSSEC disabled.';
                        }
                        break;

                    case 'apply_template':
                        $templateId = trim($_POST['template_id']);
                        $tpl        = RecordTemplates::get($templateId, $zone);
                        $zoneData   = $api->getZone($zone);
                        $deleteRR   = RecordTemplates::buildDeleteRRsets($zoneData['rrsets'] ?? [], $zone, $tpl['delete_types']);
                        $addRR      = RecordTemplates::toRRsets($tpl['records']);
                        if (!empty($deleteRR)) $api->batchPatch($zone, $deleteRR);
                        if (!empty($addRR))    $api->batchPatch($zone, $addRR);
                        AuditLog::write($serviceId, $userId, AuditLog::ACTION_TEMPLATE, $zone, 'TEMPLATE', $templateId);
                        $success = "Template '{$templateId}' applied successfully.";
                        break;
                }
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
    }

    // ------------------------------------------------------------------
    // Gather display data
    // ------------------------------------------------------------------
    $records     = [];
    $flatRecords = [];
    $dnssecEnabled = false;
    $dsRecords   = [];
    $soaData     = [];
    $auditEntries = AuditLog::forService($serviceId, 50);
    $auditTotal   = AuditLog::countForService($serviceId);

    try {
        $records = $api->getRecords($zone, PowerDNSAPI::$SUPPORTED_TYPES);
        foreach ($records as $rrset) {
            foreach ($rrset['records'] as $rec) {
                if ($rec['disabled'] ?? false) continue;
                $flatRecords[] = [
                    'name'    => $rrset['name'],
                    'type'    => $rrset['type'],
                    'ttl'     => $rrset['ttl'],
                    'content' => $rec['content'],
                ];
            }
        }

        if ($allowDNS) {
            $dnssecEnabled = $api->isDNSSECEnabled($zone);
            if ($dnssecEnabled) {
                $dsRecords = $api->getDSRecords($zone);
            }
        }

        $soaData = $api->getSOA($zone);

    } catch (Exception $e) {
        $error = $error ?: 'Could not load zone data: ' . $e->getMessage();
    }

    $recordCount = count($flatRecords);
    $quotaReached = ($maxRecords > 0 && $recordCount >= $maxRecords);

    return [
        'templatefile' => 'clientarea',
        'vars' => [
            'zone'          => $zone,
            'records'       => $flatRecords,
            'recordCount'   => $recordCount,
            'maxRecords'    => $maxRecords,
            'quotaReached'  => $quotaReached,
            'defaultTTL'    => $defaultTTL,
            'serviceId'     => $serviceId,
            'serviceUrl'    => $serviceUrl,
            'nameservers'   => $nameservers,
            'allowDNSSEC'   => $allowDNS,
            'dnssecEnabled' => $dnssecEnabled,
            'dsRecords'     => $dsRecords,
            'soaData'       => $soaData,
            'templates'     => RecordTemplates::catalog(),
            'recordTypes'   => PowerDNSAPI::$SUPPORTED_TYPES,
            'auditEntries'  => $auditEntries,
            'auditTotal'    => $auditTotal,
            'licensed'      => $licensed,
            'error'         => $error,
            'success'       => $success,
        ],
    ];
}

// =============================================================================
// Admin Area
// =============================================================================

function powerdns_premium_AdminArea(array $params)
{
    return [
        'templatefile' => 'admin_records',
        'vars' => [
            'zone'      => _pdns_p_zone($params),
            'serviceId' => $params['serviceid'],
        ],
    ];
}

function powerdns_premium_AdminCustomButtonArray()
{
    return [
        'Verify Zone Exists' => 'VerifyZone',
        'Edit SOA Record'    => 'EditSOA',
        'Recreate Zone'      => 'RecreateZone',
        'View Audit Log'     => 'ViewAuditLog',
    ];
}

function powerdns_premium_VerifyZone(array $params)
{
    try {
        $api    = _pdns_p_getClient($params);
        $zone   = _pdns_p_zone($params);
        $exists = $api->zoneExists($zone);
        $msg    = $exists
            ? "Zone '{$zone}' exists on PowerDNS."
            : "Zone '{$zone}' NOT found on PowerDNS.";
        return ['success' => $msg];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function powerdns_premium_EditSOA(array $params)
{
    // Admin POST handler for SOA edit is in hooks.php (admin hook)
    $api  = _pdns_p_getClient($params);
    $zone = _pdns_p_zone($params);
    try {
        $soa = $api->getSOA($zone);
        return ['success' => 'SOA: ' . implode(' ', $soa)];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function powerdns_premium_RecreateZone(array $params)
{
    try {
        $api  = _pdns_p_getClient($params);
        $zone = _pdns_p_zone($params);
        $ns   = _pdns_p_ns($params);
        $hm   = trim($params['configoption3'] ?: 'hostmaster.example.com');

        if ($api->zoneExists($zone)) {
            $api->deleteZone($zone);
        }
        $api->createZone($zone, $ns, $hm);
        return ['success' => "Zone '{$zone}' recreated."];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function powerdns_premium_ViewAuditLog(array $params)
{
    $entries = AuditLog::forService($params['serviceid'], 20);
    $lines   = ["Last 20 audit entries for service {$params['serviceid']}:"];
    foreach ($entries as $e) {
        $lines[] = "[{$e['created_at']}] {$e['action']} {$e['record_type']} {$e['record_name']} — {$e['record_content']}";
    }
    return ['success' => implode("\n", $lines)];
}

// =============================================================================
// Test Connection
// =============================================================================

function powerdns_premium_TestConnection(array $params)
{
    try {
        $api = _pdns_p_getClient($params);
        $api->request('GET', '/api/v1/servers');
        return ['success' => true, 'error' => ''];
    } catch (RuntimeException $e) {
        if (strpos($e->getMessage(), '404') !== false) {
            return ['success' => true, 'error' => ''];
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// =============================================================================
// Email helper
// =============================================================================

function _pdns_p_sendEmail($templateName, array $params)
{
    try {
        $result = localAPI('SendEmail', [
            'messagename' => $templateName,
            'id'          => $params['serviceid'],
        ]);
    } catch (Exception $e) {
        // Non-fatal: template may not exist yet
    }
}


// ---------------------------------------------------------------------------
// Register hooks (idempotent – safe even if WHMCS already auto-loaded the file)
// ---------------------------------------------------------------------------
require_once __DIR__ . '/hooks.php';
