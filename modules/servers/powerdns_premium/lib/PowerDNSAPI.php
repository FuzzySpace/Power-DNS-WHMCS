<?php

/**
 * PowerDNS Authoritative API Client – Premium Edition
 *
 * Extends the base client with:
 *   - DNSSEC (cryptokeys, DS records, enable/disable)
 *   - Additional record types: CNAME, CAA, NS (subdomain), ALIAS, PTR
 *   - SOA editor
 *   - Zone statistics
 *   - Slave/secondary zone support
 *   - Configurable cURL timeout
 */
class PowerDNSAPI
{
    /** @var string */
    private $baseUrl;

    /** @var string */
    private $apiKey;

    /** @var string */
    private $serverId;

    /** @var int cURL timeout in seconds */
    private $timeout;

    /** All record types the premium module supports */
    public static $SUPPORTED_TYPES = [
        'A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA', 'NS', 'ALIAS', 'PTR',
    ];

    public function __construct($baseUrl, $apiKey, $serverId = 'localhost', $timeout = 15)
    {
        $this->baseUrl  = rtrim($baseUrl, '/');
        $this->apiKey   = $apiKey;
        $this->serverId = $serverId;
        $this->timeout  = (int) $timeout;
    }

    // =========================================================================
    // Zone management
    // =========================================================================

    /**
     * Create a new zone.
     *
     * @param string   $zone
     * @param string[] $nameservers
     * @param string   $hostmaster   DNS-encoded hostmaster (dot instead of @)
     * @param string   $kind         Native | Master | Slave
     * @param string[] $masters      Master IP list (Slave zones only)
     */
    public function createZone($zone, array $nameservers, $hostmaster = '', $kind = 'Native', array $masters = [])
    {
        $zone = $this->fqdn($zone);
        $nameservers = array_values(array_filter(array_map(function ($ns) {
            $ns = strtolower(trim((string) $ns));
            if ($ns === '') {
                return '';
            }
            return rtrim($ns, '.') . '.';
        }, $nameservers)));

        if (empty($nameservers)) {
            throw new InvalidArgumentException('At least one nameserver is required.');
        }

        if (empty($hostmaster)) {
            $hostmaster = 'hostmaster.' . $zone;
        }

        $body = [
            'name'        => $zone,
            'kind'        => $kind,
            'nameservers' => $nameservers,
        ];

        if (!empty($masters)) {
            $body['masters'] = $masters;
        }

        // Native and Master get a default SOA
        if ($kind !== 'Slave') {
            $body['rrsets'] = [
                [
                    'name'    => $zone,
                    'type'    => 'SOA',
                    'ttl'     => 3600,
                    'records' => [[
                        'content'  => sprintf(
                            '%s %s 1 10800 3600 604800 300',
                            $nameservers[0],
                            $this->normalizeHostmaster($hostmaster)
                        ),
                        'disabled' => false,
                    ]],
                ],
            ];
        }

        return $this->request('POST', "/api/v1/servers/{$this->serverId}/zones", $body);
    }

    public function deleteZone($zone)
    {
        return $this->request('DELETE', "/api/v1/servers/{$this->serverId}/zones/{$this->fqdn($zone)}");
    }

    public function getZone($zone)
    {
        return $this->request('GET', "/api/v1/servers/{$this->serverId}/zones/{$this->fqdn($zone)}");
    }

    public function zoneExists($zone)
    {
        try {
            $this->getZone($zone);
            return true;
        } catch (RuntimeException $e) {
            if (strpos($e->getMessage(), '404') !== false || strpos($e->getMessage(), 'Could not find') !== false) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * List all zones on the server.
     */
    public function listZones()
    {
        return $this->request('GET', "/api/v1/servers/{$this->serverId}/zones");
    }

    /**
     * Return the total number of records across all types for a zone.
     */
    public function getRecordCount($zone)
    {
        $data   = $this->getZone($zone);
        $rrsets = $data['rrsets'] ?? [];
        $count  = 0;
        foreach ($rrsets as $rr) {
            if (!in_array(strtoupper($rr['type']), ['SOA', 'NS'], true) || $rr['name'] !== $this->fqdn($zone)) {
                $count += count($rr['records']);
            }
        }
        return $count;
    }

    // =========================================================================
    // SOA management
    // =========================================================================

    /**
     * Read the zone's SOA record and return parsed fields.
     *
     * @return array keys: primary_ns, hostmaster, serial, refresh, retry, expire, minimum
     */
    public function getSOA($zone)
    {
        $data = $this->getZone($zone);
        foreach ($data['rrsets'] as $rr) {
            if (strtoupper($rr['type']) === 'SOA') {
                $parts = preg_split('/\s+/', trim($rr['records'][0]['content']));
                return [
                    'ttl'        => $rr['ttl'],
                    'primary_ns' => $parts[0] ?? '',
                    'hostmaster' => $parts[1] ?? '',
                    'serial'     => (int) ($parts[2] ?? 1),
                    'refresh'    => (int) ($parts[3] ?? 10800),
                    'retry'      => (int) ($parts[4] ?? 3600),
                    'expire'     => (int) ($parts[5] ?? 604800),
                    'minimum'    => (int) ($parts[6] ?? 300),
                ];
            }
        }
        throw new RuntimeException("SOA record not found for zone: {$zone}");
    }

    /**
     * Replace the SOA record with new values.
     */
    public function updateSOA($zone, array $soa)
    {
        $zone    = $this->fqdn($zone);
        $content = sprintf(
            '%s %s %d %d %d %d %d',
            $this->fqdn($soa['primary_ns']),
            $this->normalizeHostmaster($soa['hostmaster']),
            $soa['serial'],
            $soa['refresh'],
            $soa['retry'],
            $soa['expire'],
            $soa['minimum']
        );

        $rrset = [
            'name'       => $zone,
            'type'       => 'SOA',
            'ttl'        => (int) ($soa['ttl'] ?? 3600),
            'changetype' => 'REPLACE',
            'records'    => [['content' => $content, 'disabled' => false]],
        ];

        return $this->request('PATCH', "/api/v1/servers/{$this->serverId}/zones/{$zone}", ['rrsets' => [$rrset]]);
    }

    // =========================================================================
    // Record management
    // =========================================================================

    /**
     * Fetch RRsets filtered to the given types.
     */
    public function getRecords($zone, array $types = null)
    {
        if ($types === null) {
            $types = self::$SUPPORTED_TYPES;
        }
        $data   = $this->getZone($zone);
        $rrsets = $data['rrsets'] ?? [];

        return array_values(array_filter($rrsets, function ($rr) use ($types) {
            return in_array(strtoupper($rr['type']), array_map('strtoupper', $types), true);
        }));
    }

    /**
     * Add or append a record to a zone.
     *
     * @param string $zone
     * @param string $name     Relative label, @ for apex, or FQDN
     * @param string $type
     * @param string $content  Already-formatted content string
     * @param int    $ttl
     * @param bool   $append   true = merge with existing same-name+type RRset
     */
    public function addRecord($zone, $name, $type, $content, $ttl = 300, $append = true)
    {
        $zone  = $this->fqdn($zone);
        $name  = $this->resolveName($name, $zone);
        $type  = strtoupper($type);

        $existingRecords = [];
        if ($append) {
            try {
                $zoneData = $this->getZone($zone);
                foreach ($zoneData['rrsets'] as $rr) {
                    if ($rr['name'] === $name && strtoupper($rr['type']) === $type) {
                        $existingRecords = $rr['records'];
                        $ttl             = $rr['ttl'];
                        break;
                    }
                }
            } catch (RuntimeException $e) {
                // proceed with empty set
            }
        }

        // Dedup exact match
        foreach ($existingRecords as $r) {
            if ($r['content'] === $content) {
                return null; // already exists
            }
        }

        $existingRecords[] = ['content' => $content, 'disabled' => false];

        $rrset = [
            'name'       => $name,
            'type'       => $type,
            'ttl'        => (int) $ttl,
            'changetype' => 'REPLACE',
            'records'    => $existingRecords,
        ];

        return $this->request('PATCH', "/api/v1/servers/{$this->serverId}/zones/{$zone}", ['rrsets' => [$rrset]]);
    }

    /**
     * Edit an existing record: delete old content, insert new content.
     */
    public function editRecord($zone, $name, $type, $oldContent, $newContent, $newTTL = null)
    {
        $zone = $this->fqdn($zone);
        $name = $this->resolveName($name, $zone);
        $type = strtoupper($type);

        $zoneData        = $this->getZone($zone);
        $remainingRecords = [];
        $currentTTL      = $newTTL ?? 300;

        foreach ($zoneData['rrsets'] as $rr) {
            if ($rr['name'] === $name && strtoupper($rr['type']) === $type) {
                $currentTTL = $newTTL ?? $rr['ttl'];
                foreach ($rr['records'] as $r) {
                    if ($r['content'] !== $oldContent) {
                        $remainingRecords[] = $r;
                    }
                }
                break;
            }
        }

        $remainingRecords[] = ['content' => $newContent, 'disabled' => false];

        $rrset = [
            'name'       => $name,
            'type'       => $type,
            'ttl'        => (int) $currentTTL,
            'changetype' => 'REPLACE',
            'records'    => $remainingRecords,
        ];

        return $this->request('PATCH', "/api/v1/servers/{$this->serverId}/zones/{$zone}", ['rrsets' => [$rrset]]);
    }

    /**
     * Delete a specific record content, or the entire RRset if $content is null.
     */
    public function deleteRecord($zone, $name, $type, $content = null)
    {
        $zone = $this->fqdn($zone);
        $name = $this->resolveName($name, $zone);
        $type = strtoupper($type);

        if ($content === null) {
            $rrset = ['name' => $name, 'type' => $type, 'changetype' => 'DELETE', 'records' => []];
            return $this->request('PATCH', "/api/v1/servers/{$this->serverId}/zones/{$zone}", ['rrsets' => [$rrset]]);
        }

        $zoneData         = $this->getZone($zone);
        $remainingRecords = [];
        $ttl              = 300;

        foreach ($zoneData['rrsets'] as $rr) {
            if ($rr['name'] === $name && strtoupper($rr['type']) === $type) {
                $ttl = $rr['ttl'];
                foreach ($rr['records'] as $r) {
                    if ($r['content'] !== $content) {
                        $remainingRecords[] = $r;
                    }
                }
                break;
            }
        }

        if (empty($remainingRecords)) {
            $rrset = ['name' => $name, 'type' => $type, 'changetype' => 'DELETE', 'records' => []];
        } else {
            $rrset = ['name' => $name, 'type' => $type, 'ttl' => $ttl, 'changetype' => 'REPLACE', 'records' => $remainingRecords];
        }

        return $this->request('PATCH', "/api/v1/servers/{$this->serverId}/zones/{$zone}", ['rrsets' => [$rrset]]);
    }

    /**
     * Apply a batch of RRset changes in one API call.
     *
     * @param string  $zone
     * @param array[] $rrsets Each item: ['name','type','ttl','changetype','records']
     */
    public function batchPatch($zone, array $rrsets)
    {
        $zone = $this->fqdn($zone);
        return $this->request('PATCH', "/api/v1/servers/{$this->serverId}/zones/{$zone}", ['rrsets' => $rrsets]);
    }

    // =========================================================================
    // Suspend / Unsuspend
    // =========================================================================

    public function disableZoneRecords($zone)
    {
        $this->setRecordsDisabled($zone, true);
    }

    public function enableZoneRecords($zone)
    {
        $this->setRecordsDisabled($zone, false);
    }

    private function setRecordsDisabled($zone, $disabled)
    {
        $zone   = $this->fqdn($zone);
        $data   = $this->getZone($zone);
        $skip   = ['SOA'];
        $rrsets = [];

        foreach ($data['rrsets'] as $rr) {
            if (in_array(strtoupper($rr['type']), $skip, true)) {
                continue;
            }
            // Keep apex NS enabled so the zone still delegates correctly
            if (strtoupper($rr['type']) === 'NS' && $rr['name'] === $zone) {
                continue;
            }
            $records = array_map(function ($r) use ($disabled) {
                $r['disabled'] = $disabled;
                return $r;
            }, $rr['records']);

            $rrsets[] = [
                'name' => $rr['name'], 'type' => $rr['type'],
                'ttl'  => $rr['ttl'], 'changetype' => 'REPLACE',
                'records' => $records,
            ];
        }

        if (!empty($rrsets)) {
            $this->request('PATCH', "/api/v1/servers/{$this->serverId}/zones/{$zone}", ['rrsets' => $rrsets]);
        }
    }

    // =========================================================================
    // DNSSEC
    // =========================================================================

    /**
     * Enable DNSSEC signing on a zone (creates default key if none exist).
     */
    public function enableDNSSEC($zone)
    {
        $zone = $this->fqdn($zone);
        // First check if already enabled
        $zoneData = $this->getZone($zone);
        if (!empty($zoneData['dnssec'])) {
            return; // already enabled
        }
        // Create a KSK (key-signing key) with algorithm 13 (ECDSA P-256 SHA-256)
        $this->request('POST', "/api/v1/servers/{$this->serverId}/zones/{$zone}/cryptokeys", [
            'keytype'   => 'ksk',
            'active'    => true,
            'algorithm' => 'ecdsa256',
        ]);
        // Create a ZSK
        $this->request('POST', "/api/v1/servers/{$this->serverId}/zones/{$zone}/cryptokeys", [
            'keytype'   => 'zsk',
            'active'    => true,
            'algorithm' => 'ecdsa256',
        ]);
    }

    /**
     * Disable DNSSEC: delete all cryptokeys.
     */
    public function disableDNSSEC($zone)
    {
        $zone = $this->fqdn($zone);
        $keys = $this->request('GET', "/api/v1/servers/{$this->serverId}/zones/{$zone}/cryptokeys");
        foreach ($keys as $key) {
            $this->request('DELETE', "/api/v1/servers/{$this->serverId}/zones/{$zone}/cryptokeys/{$key['id']}");
        }
    }

    /**
     * Return all cryptokeys for a zone.
     */
    public function getCryptokeys($zone)
    {
        $zone = $this->fqdn($zone);
        try {
            return $this->request('GET', "/api/v1/servers/{$this->serverId}/zones/{$zone}/cryptokeys") ?? [];
        } catch (RuntimeException $e) {
            return [];
        }
    }

    /**
     * Return DS records ready for submission to a registrar.
     * Each element: ['keytag','algorithm','digesttype','digest']
     */
    public function getDSRecords($zone)
    {
        $keys = $this->getCryptokeys($zone);
        $ds   = [];
        foreach ($keys as $key) {
            if (!empty($key['ds'])) {
                foreach ($key['ds'] as $dsRecord) {
                    // PowerDNS returns DS as "keytag algorithm digesttype digest"
                    $parts = preg_split('/\s+/', trim($dsRecord));
                    if (count($parts) >= 4) {
                        $ds[] = [
                            'keytag'     => $parts[0],
                            'algorithm'  => $parts[1],
                            'digesttype' => $parts[2],
                            'digest'     => $parts[3],
                            'raw'        => $dsRecord,
                            'keytype'    => $key['keytype'] ?? '',
                            'active'     => $key['active'] ?? false,
                        ];
                    }
                }
            }
        }
        return $ds;
    }

    /**
     * Check whether DNSSEC is currently enabled (has active keys).
     */
    public function isDNSSECEnabled($zone)
    {
        $keys = $this->getCryptokeys($zone);
        foreach ($keys as $key) {
            if (!empty($key['active'])) {
                return true;
            }
        }
        return false;
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /**
     * Resolve a user-supplied name to a fully-qualified name within the zone.
     *
     * Rules:
     *   '@' or '' → zone apex (zone itself)
     *   'sub'     → sub.zone.
     *   'sub.zone.' or already FQDN → returned as-is
     */
    private function resolveName($name, $zoneFqdn)
    {
        $name     = trim($name);
        $zoneFqdn = rtrim($zoneFqdn, '.') . '.';

        if ($name === '' || $name === '@') {
            return $zoneFqdn;
        }

        if (substr($name, -1) === '.') {
            return $name; // already FQDN
        }

        // Contains dots and ends with zone (without trailing dot)
        $zoneNoTrail = rtrim($zoneFqdn, '.');
        if (substr($name, -(strlen($zoneNoTrail))) === $zoneNoTrail) {
            return $name . '.';
        }

        return $name . '.' . $zoneNoTrail . '.';
    }

    private function fqdn($name)
    {
        $name = trim($name);
        return (substr($name, -1) === '.') ? $name : $name . '.';
    }

    private function normalizeHostmaster($hostmaster)
    {
        $hm = trim($hostmaster);
        if (strpos($hm, '@') !== false) {
            [$local, $domain] = explode('@', $hm, 2);
            $hm = $local . '.' . $domain;
        }
        return rtrim($hm, '.') . '.';
    }

    /**
     * Execute an HTTP request.
     *
     * @throws RuntimeException
     */
    public function request($method, $path, $body = null)
    {
        $url     = $this->baseUrl . $path;
        $headers = [
            'X-API-Key: '    . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CUSTOMREQUEST  => $method,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new RuntimeException("Connection error: {$curlError}");
        }

        if ($httpCode === 204) {
            return null;
        }

        $decoded = json_decode($response, true);

        if ($httpCode >= 400) {
            $msg = isset($decoded['error']) ? $decoded['error'] : "HTTP {$httpCode}";
            throw new RuntimeException("PowerDNS API error {$httpCode}: {$msg}");
        }

        return $decoded;
    }
}
