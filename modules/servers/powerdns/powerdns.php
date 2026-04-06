<?php

/**
 * PowerDNS WHMCS Provisioning Module
 *
 * Provides a purchasable DNS hosting product. When a customer orders and
 * pays, WHMCS provisions a zone on your PowerDNS authoritative server.
 * The client area exposes a self-service DNS record manager for
 * A, AAAA, MX, TXT and SRV record types.
 *
 * Installation:
 *   1. Copy this directory to <whmcs>/modules/servers/powerdns/
 *   2. In WHMCS Admin → Setup → Products/Services → Servers, add a new
 *      server of type "PowerDNS" and fill in the API URL and API key.
 *   3. Create a Product/Service, choose "PowerDNS" as the module, and
 *      configure the nameservers and default TTL in the module settings.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/PowerDNSAPI.php';

// ---------------------------------------------------------------------------
// Module metadata
// ---------------------------------------------------------------------------

function powerdns_MetaData()
{
    return [
        'DisplayName'               => 'PowerDNS Authoritative DNS',
        'APIVersion'                => '1.1',
        'RequiresServer'            => true,
        'DefaultNonSSLPort'         => '8081',
        'DefaultSSLPort'            => '8081',
        'ServiceSingleSignOnLabel'  => false,
        'ListClientsWithStatus'     => ['Active', 'Suspended'],
    ];
}

// ---------------------------------------------------------------------------
// Server-level configuration (entered in the WHMCS server record)
// These map to the fields shown when you add/edit a server in WHMCS Admin.
// ---------------------------------------------------------------------------

function powerdns_ConfigOptions()
{
    return [
        'Nameserver 1' => [
            'Type'        => 'text',
            'Size'        => 50,
            'Default'     => 'ns1.example.com',
            'Description' => 'Primary nameserver hostname (used in SOA and NS records)',
        ],
        'Nameserver 2' => [
            'Type'        => 'text',
            'Size'        => 50,
            'Default'     => 'ns2.example.com',
            'Description' => 'Secondary nameserver hostname',
        ],
        'Hostmaster Email' => [
            'Type'        => 'text',
            'Size'        => 50,
            'Default'     => 'hostmaster.example.com',
            'Description' => 'Hostmaster e-mail in DNS format (@ replaced by dot)',
        ],
        'Default TTL' => [
            'Type'        => 'text',
            'Size'        => 10,
            'Default'     => '300',
            'Description' => 'Default TTL (seconds) for new DNS records',
        ],
        'PowerDNS Server ID' => [
            'Type'        => 'text',
            'Size'        => 30,
            'Default'     => 'localhost',
            'Description' => 'PowerDNS internal server name. Almost always "localhost" — do NOT enter the hostname here.',
        ],
    ];
}

// ---------------------------------------------------------------------------
// Internal helper – build an authenticated API client from WHMCS params
// ---------------------------------------------------------------------------

function _powerdns_getClient(array $params)
{
    $host     = $params['serverhostname'] ?: $params['serverip'];
    $port     = $params['serverport'] ?: 8081;
    $apiKey   = $params['serverpassword']; // stored in the Server Password field
    $serverId = trim($params['configoption5'] ?: 'localhost');
    $scheme   = ($params['serversecure'] === 'on') ? 'https' : 'http';

    $baseUrl  = "{$scheme}://{$host}:{$port}";

    return new PowerDNSAPI($baseUrl, $apiKey, $serverId);
}

function _powerdns_nameservers(array $params)
{
    $ns = [];
    if (!empty($params['configoption1'])) {
        $ns[] = rtrim(strtolower(trim($params['configoption1'])), '.');
    }
    if (!empty($params['configoption2'])) {
        $ns[] = rtrim(strtolower(trim($params['configoption2'])), '.');
    }
    return $ns ?: ['ns1.example.com', 'ns2.example.com'];
}

function _powerdns_zoneName(array $params)
{
    // WHMCS stores the domain (e.g. "customer.com") in $params['domain']
    return _powerdns_toAsciiDomain($params['domain']);
}

/**
 * Normalize a domain name: lowercase, trim, and convert to punycode (ACE)
 * so that IDN domains like münchen.de become xn--mnchen-3ya.de before being
 * sent to PowerDNS.  Falls back to plain strtolower when intl is unavailable.
 */
function _powerdns_toAsciiDomain($domain)
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

// ---------------------------------------------------------------------------
// Provisioning hooks
// ---------------------------------------------------------------------------

/**
 * Create Account – called when the service is first provisioned.
 */
function powerdns_CreateAccount(array $params)
{
    try {
        $api        = _powerdns_getClient($params);
        $zone       = _powerdns_zoneName($params);
        $nameservers = _powerdns_nameservers($params);
        $hostmaster  = trim($params['configoption3'] ?: 'hostmaster.example.com');

        if ($api->zoneExists($zone)) {
            // Already exists – idempotent, treat as success
            return 'success';
        }

        $api->createZone($zone, $nameservers, $hostmaster);
        return 'success';
    } catch (Exception $e) {
        logActivity('PowerDNS module error [' . $params['domain'] . ']: ' . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Suspend Account – disable all records so DNS stops resolving.
 */
function powerdns_SuspendAccount(array $params)
{
    try {
        $api  = _powerdns_getClient($params);
        $zone = _powerdns_zoneName($params);
        $api->disableZoneRecords($zone);
        return 'success';
    } catch (Exception $e) {
        logActivity('PowerDNS module error [' . $params['domain'] . ']: ' . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Unsuspend Account – re-enable all records.
 */
function powerdns_UnsuspendAccount(array $params)
{
    try {
        $api  = _powerdns_getClient($params);
        $zone = _powerdns_zoneName($params);
        $api->enableZoneRecords($zone);
        return 'success';
    } catch (Exception $e) {
        logActivity('PowerDNS module error [' . $params['domain'] . ']: ' . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Terminate Account – delete the zone entirely.
 */
function powerdns_TerminateAccount(array $params)
{
    try {
        $api  = _powerdns_getClient($params);
        $zone = _powerdns_zoneName($params);
        $api->deleteZone($zone);
        return 'success';
    } catch (Exception $e) {
        logActivity('PowerDNS module error [' . $params['domain'] . ']: ' . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

// ---------------------------------------------------------------------------
// Client Area
// ---------------------------------------------------------------------------

/**
 * Adds "Manage DNS Records" to the WHMCS service actions sidebar.
 * Clicking it triggers powerdns_managedns() which redirects to the
 * manager view via GET parameter (avoids the modop=custom blank page).
 */
function powerdns_ClientAreaCustomButtonArray()
{
    return [
        'Manage DNS Records' => 'managedns',
    ];
}

/**
 * Sidebar button handler. Redirects to ?view=managedns on the same
 * service page so ClientArea() renders normally (not in modop=custom mode).
 */
function powerdns_managedns(array $params)
{
    $url = 'clientarea.php?action=productdetails&id=' . (int) $params['serviceid'] . '&view=managedns';
    header('Location: ' . $url, true, 302);
    exit;
}

/**
 * Return the template and variables for the client-facing area.
 * Default view: zone overview (nameservers, record count, quick cards).
 * With ?view=managedns: full DNS record manager.
 */
function powerdns_ClientArea(array $params)
{
    if ($params['status'] !== 'Active') {
        return [
            'templatefile' => 'inactive',
            'vars'         => ['status' => $params['status']],
        ];
    }

    $zone        = _powerdns_zoneName($params);
    $serviceId   = $params['serviceid'];
    $nameservers = _powerdns_nameservers($params);
    $serviceUrl  = 'clientarea.php?action=productdetails&id=' . $serviceId;
    $manageUrl   = $serviceUrl . '&view=managedns';

    // Route: show manager when ?view=managedns is in URL or WHMCS customaction
    $view = $_GET['view'] ?? ($params['customaction'] ?? '');
    $showManager = ($view === 'managedns')
                || ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['powerdns_action']));

    // ── Overview page ──────────────────────────────────────────────────────
    if (!$showManager) {
        $recordCount = 0;
        $zoneError   = '';
        try {
            $api     = _powerdns_getClient($params);
            $records = $api->getRecords($zone, ['A', 'AAAA', 'MX', 'TXT', 'SRV']);
            foreach ($records as $rr) {
                foreach ($rr['records'] as $r) {
                    if (!($r['disabled'] ?? false)) {
                        $recordCount++;
                    }
                }
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
                'serviceId'   => $serviceId,
                'manageUrl'   => $manageUrl,
                'error'       => $zoneError,
            ],
        ];
    }

    // ── DNS Manager page ───────────────────────────────────────────────────
    $api        = _powerdns_getClient($params);
    $defaultTTL = (int) ($params['configoption4'] ?: 300);
    $error      = '';
    $success    = '';

    // Non-AJAX form submission fallback
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['powerdns_action'])) {
        check_token('WHMCS.clientarea');
        $action = $_POST['powerdns_action'];
        try {
            switch ($action) {
                case 'add_record':
                    $type    = strtoupper(trim($_POST['record_type']));
                    $name    = trim($_POST['record_name']);
                    $ttl     = max(60, (int) ($_POST['record_ttl'] ?: $defaultTTL));
                    if (!in_array($type, ['A', 'AAAA', 'MX', 'TXT', 'SRV'], true)) {
                        throw new InvalidArgumentException("Unsupported record type: {$type}");
                    }
                    $content = _powerdns_buildRecordContent($type, $_POST);
                    $api->addRecord($zone, $name ?: $zone, $type, $content, $ttl, true);
                    $success = 'Record added successfully.';
                    break;
                case 'delete_record':
                    $type    = strtoupper(trim($_POST['record_type']));
                    $name    = trim($_POST['record_name']);
                    $content = trim($_POST['record_content']);
                    $api->deleteRecord($zone, $name, $type, $content);
                    $success = 'Record deleted successfully.';
                    break;
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }

    // Fetch current records
    $flatRecords = [];
    try {
        $records = $api->getRecords($zone, ['A', 'AAAA', 'MX', 'TXT', 'SRV']);
        foreach ($records as $rrset) {
            foreach ($rrset['records'] as $record) {
                if ($record['disabled'] ?? false) {
                    continue;
                }
                $flatRecords[] = [
                    'name'    => $rrset['name'],
                    'type'    => $rrset['type'],
                    'ttl'     => $rrset['ttl'],
                    'content' => $record['content'],
                ];
            }
        }
    } catch (Exception $e) {
        $error = $error ?: 'Unable to load DNS records: ' . $e->getMessage();
    }

    return [
        'templatefile' => 'clientarea',
        'vars' => [
            'zone'        => $zone,
            'nameservers' => $nameservers,
            'records'     => $flatRecords,
            'defaultTTL'  => $defaultTTL,
            'serviceId'   => $serviceId,
            'serviceUrl'  => $serviceUrl,
            'error'       => $error,
            'success'     => $success,
        ],
    ];
}

// ---------------------------------------------------------------------------
// Record content builder – translates form fields into PowerDNS content string
// ---------------------------------------------------------------------------

function _powerdns_buildRecordContent($type, array $post)
{
    switch ($type) {
        case 'A':
        case 'AAAA':
            $ip = trim($post['record_value'] ?? '');
            if (empty($ip)) {
                throw new InvalidArgumentException("IP address is required.");
            }
            if ($type === 'A' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new InvalidArgumentException("Invalid IPv4 address: {$ip}");
            }
            if ($type === 'AAAA' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new InvalidArgumentException("Invalid IPv6 address: {$ip}");
            }
            return $ip;

        case 'MX':
            $priority = (int) ($post['record_priority'] ?? 10);
            $target   = rtrim(trim($post['record_value'] ?? ''), '.') . '.';
            if (empty(rtrim($target, '.'))) {
                throw new InvalidArgumentException("MX target is required.");
            }
            return "{$priority} {$target}";

        case 'TXT':
            $value = trim($post['record_value'] ?? '');
            if (empty($value)) {
                throw new InvalidArgumentException("TXT content is required.");
            }
            // PowerDNS expects TXT content wrapped in double quotes
            if (substr($value, 0, 1) !== '"') {
                $value = '"' . addslashes($value) . '"';
            }
            return $value;

        case 'SRV':
            $priority = (int) ($post['srv_priority'] ?? 10);
            $weight   = (int) ($post['srv_weight']   ?? 0);
            $port     = (int) ($post['srv_port']     ?? 0);
            $target   = rtrim(trim($post['record_value'] ?? ''), '.') . '.';
            if ($port < 1 || $port > 65535) {
                throw new InvalidArgumentException("SRV port must be between 1 and 65535.");
            }
            if (empty(rtrim($target, '.'))) {
                throw new InvalidArgumentException("SRV target is required.");
            }
            return "{$priority} {$weight} {$port} {$target}";

        default:
            throw new InvalidArgumentException("Unsupported record type: {$type}");
    }
}

// ---------------------------------------------------------------------------
// Admin area custom button (optional – adds a "Sync Zone" button in admin)
// ---------------------------------------------------------------------------

function powerdns_AdminCustomButtonArray()
{
    return [
        'Verify Zone Exists' => 'VerifyZone',
    ];
}

function powerdns_VerifyZone(array $params)
{
    try {
        $api   = _powerdns_getClient($params);
        $zone  = _powerdns_zoneName($params);
        $exists = $api->zoneExists($zone);
        return ['success' => $exists ? "Zone '{$zone}' exists on PowerDNS." : "Zone '{$zone}' NOT found on PowerDNS."];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

// ---------------------------------------------------------------------------
// Test connection (shown in server config page)
// ---------------------------------------------------------------------------

function powerdns_TestConnection(array $params)
{
    try {
        $api    = _powerdns_getClient($params);
        $result = $api->getZone('localhost'); // intentionally bogus – we just want a 404 not a connection error
    } catch (RuntimeException $e) {
        if (strpos($e->getMessage(), '404') !== false || strpos($e->getMessage(), 'Could not find') !== false) {
            // Got a real 404 from PowerDNS → connection works, key is valid
            return ['success' => true, 'error' => ''];
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
    return ['success' => true, 'error' => ''];
}


// ---------------------------------------------------------------------------
// Register hooks (idempotent – safe even if WHMCS already auto-loaded the file)
// ---------------------------------------------------------------------------
require_once __DIR__ . '/hooks.php';
