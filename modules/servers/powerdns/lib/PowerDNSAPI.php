<?php

/**
 * PowerDNS Authoritative API Client
 *
 * Communicates with the PowerDNS HTTP API (v1).
 */
class PowerDNSAPI
{
    /** @var string Base URL of the PowerDNS API, e.g. http://ns1.example.com:8081 */
    private $baseUrl;

    /** @var string X-API-Key value */
    private $apiKey;

    /** @var string PowerDNS server identifier (usually "localhost") */
    private $serverId;

    /**
     * @param string $baseUrl   e.g. "http://ns1.example.com:8081"
     * @param string $apiKey    PowerDNS API key
     * @param string $serverId  PowerDNS server id (default: localhost)
     */
    public function __construct($baseUrl, $apiKey, $serverId = 'localhost')
    {
        $this->baseUrl  = rtrim($baseUrl, '/');
        $this->apiKey   = $apiKey;
        $this->serverId = $serverId;
    }

    // -------------------------------------------------------------------------
    // Zone management
    // -------------------------------------------------------------------------

    /**
     * Create a new authoritative zone with default SOA + NS records.
     *
     * @param  string   $zone       Fully-qualified zone name (e.g. "example.com.")
     * @param  string[] $nameservers List of NS hostnames (FQDN with trailing dot)
     * @param  string   $hostmaster  Hostmaster email encoded as DNS name
     * @return array    API response body
     * @throws RuntimeException
     */
    public function createZone($zone, array $nameservers, $hostmaster = '')
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
            'kind'        => 'Native',
            'nameservers' => $nameservers,
            'rrsets'      => [
                [
                    'name'    => $zone,
                    'type'    => 'SOA',
                    'ttl'     => 3600,
                    'records' => [
                        [
                            'content'  => sprintf(
                                '%s %s 1 10800 3600 604800 300',
                                $nameservers[0],
                                $this->normalizeHostmaster($hostmaster)
                            ),
                            'disabled' => false,
                        ],
                    ],
                ],
            ],
        ];

        return $this->request('POST', "/api/v1/servers/{$this->serverId}/zones", $body);
    }

    /**
     * Delete a zone and all its records.
     *
     * @param string $zone
     */
    public function deleteZone($zone)
    {
        $zone = $this->fqdn($zone);
        return $this->request('DELETE', "/api/v1/servers/{$this->serverId}/zones/{$zone}");
    }

    /**
     * Retrieve full zone data including all RRsets.
     *
     * @param  string $zone
     * @return array
     */
    public function getZone($zone)
    {
        $zone = $this->fqdn($zone);
        return $this->request('GET', "/api/v1/servers/{$this->serverId}/zones/{$zone}");
    }

    /**
     * Check whether a zone exists.
     *
     * @param  string $zone
     * @return bool
     */
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

    // -------------------------------------------------------------------------
    // Record helpers
    // -------------------------------------------------------------------------

    /**
     * Return only the RRsets that belong to the given supported types.
     *
     * @param  string   $zone
     * @param  string[] $types e.g. ['A','AAAA','MX','TXT','SRV']
     * @return array
     */
    public function getRecords($zone, array $types = ['A', 'AAAA', 'MX', 'TXT', 'SRV'])
    {
        $data   = $this->getZone($zone);
        $rrsets = isset($data['rrsets']) ? $data['rrsets'] : [];

        return array_values(array_filter($rrsets, function ($rr) use ($types) {
            return in_array(strtoupper($rr['type']), $types, true);
        }));
    }

    /**
     * Add or replace a single DNS record.
     *
     * Existing records of the same name+type are replaced unless $append is
     * true, in which case the new record is merged with existing ones.
     *
     * @param string $zone
     * @param string $name     Record name (relative or FQDN)
     * @param string $type     Record type: A|AAAA|MX|TXT|SRV
     * @param string $content  Record content (already formatted for PowerDNS)
     * @param int    $ttl
     * @param bool   $append   Merge with existing records of same name+type
     */
    public function addRecord($zone, $name, $type, $content, $ttl = 300, $append = true)
    {
        $zone  = $this->fqdn($zone);
        $name  = $this->fqdn($name, $zone);
        $type  = strtoupper($type);

        $existingRecords = [];
        if ($append) {
            try {
                $zoneData = $this->getZone($zone);
                foreach ($zoneData['rrsets'] as $rr) {
                    if ($rr['name'] === $name && strtoupper($rr['type']) === $type) {
                        $existingRecords = $rr['records'];
                        $ttl = $rr['ttl']; // keep existing TTL
                        break;
                    }
                }
            } catch (RuntimeException $e) {
                // zone not found or other error – proceed with empty set
            }
        }

        // Avoid exact duplicate
        foreach ($existingRecords as $r) {
            if ($r['content'] === $content) {
                return; // already exists
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
     * Delete one specific record content from a name+type RRset.
     * If content is null, the entire RRset is deleted.
     *
     * @param string      $zone
     * @param string      $name
     * @param string      $type
     * @param string|null $content
     */
    public function deleteRecord($zone, $name, $type, $content = null)
    {
        $zone = $this->fqdn($zone);
        $name = $this->fqdn($name, $zone);
        $type = strtoupper($type);

        if ($content === null) {
            // Delete entire RRset
            $rrset = [
                'name'       => $name,
                'type'       => $type,
                'changetype' => 'DELETE',
                'records'    => [],
            ];
            return $this->request('PATCH', "/api/v1/servers/{$this->serverId}/zones/{$zone}", ['rrsets' => [$rrset]]);
        }

        // Remove only the specific record content, keep the rest
        $zoneData        = $this->getZone($zone);
        $remainingRecords = [];
        $ttl             = 300;

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
            $rrset = [
                'name'       => $name,
                'type'       => $type,
                'changetype' => 'DELETE',
                'records'    => [],
            ];
        } else {
            $rrset = [
                'name'       => $name,
                'type'       => $type,
                'ttl'        => $ttl,
                'changetype' => 'REPLACE',
                'records'    => $remainingRecords,
            ];
        }

        return $this->request('PATCH', "/api/v1/servers/{$this->serverId}/zones/{$zone}", ['rrsets' => [$rrset]]);
    }

    // -------------------------------------------------------------------------
    // Zone enable / disable (used for suspend / unsuspend)
    // PowerDNS Native zones have no built-in disable flag; we toggle all
    // non-SOA/NS records by marking them disabled.
    // -------------------------------------------------------------------------

    /**
     * Disable all A/AAAA/MX/TXT/SRV records in a zone (suspend).
     *
     * @param string $zone
     */
    public function disableZoneRecords($zone)
    {
        $this->setRecordsDisabled($zone, true);
    }

    /**
     * Re-enable all records in a zone (unsuspend).
     *
     * @param string $zone
     */
    public function enableZoneRecords($zone)
    {
        $this->setRecordsDisabled($zone, false);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function setRecordsDisabled($zone, $disabled)
    {
        $zone    = $this->fqdn($zone);
        $data    = $this->getZone($zone);
        $types   = ['A', 'AAAA', 'MX', 'TXT', 'SRV', 'CNAME'];
        $rrsets  = [];

        foreach ($data['rrsets'] as $rr) {
            if (!in_array(strtoupper($rr['type']), $types, true)) {
                continue;
            }
            $records = array_map(function ($r) use ($disabled) {
                $r['disabled'] = $disabled;
                return $r;
            }, $rr['records']);

            $rrsets[] = [
                'name'       => $rr['name'],
                'type'       => $rr['type'],
                'ttl'        => $rr['ttl'],
                'changetype' => 'REPLACE',
                'records'    => $records,
            ];
        }

        if (!empty($rrsets)) {
            $this->request('PATCH', "/api/v1/servers/{$this->serverId}/zones/{$zone}", ['rrsets' => $rrsets]);
        }
    }

    /**
     * Ensure a name ends with a dot (FQDN).
     * If $zone is supplied and $name does not already contain $zone, append it.
     *
     * @param  string      $name
     * @param  string|null $zone Already FQDN zone name (with trailing dot)
     * @return string
     */
    private function fqdn($name, $zone = null)
    {
        $name = trim($name);

        // Already absolute
        if (substr($name, -1) === '.') {
            return $name;
        }

        // Zone apex shorthand
        if ($zone !== null && ($name === '@' || $name === '')) {
            return $zone;
        }

        // Bare label (no dots) – relative to zone
        if ($zone !== null && strpos($name, '.') === false) {
            return $name . '.' . $zone;
        }

        // Has dots – treat as an absolute hostname, just add trailing dot
        return $name . '.';
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
     * Execute an HTTP request against the PowerDNS API.
     *
     * @param  string     $method GET|POST|PATCH|DELETE
     * @param  string     $path
     * @param  array|null $body
     * @return array|null Decoded JSON body (null for 204 No Content)
     * @throws RuntimeException on HTTP errors
     */
    private function request($method, $path, $body = null)
    {
        $url = $this->baseUrl . $path;

        $headers = [
            'X-API-Key: ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response   = curl_exec($ch);
        $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError  = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new RuntimeException("cURL error: {$curlError}");
        }

        if ($httpCode === 204) {
            return null; // No Content – success
        }

        $decoded = json_decode($response, true);

        if ($httpCode >= 400) {
            $msg = isset($decoded['error']) ? $decoded['error'] : $response;
            if ($httpCode === 404 && strpos($this->serverId, '.') !== false) {
                $msg .= ' — Hint: "PowerDNS Server ID" is usually "localhost", not a hostname.';
            } elseif ($httpCode === 401 || $httpCode === 403) {
                $msg .= ' — Check that the API Key in the server configuration is correct.';
            } elseif ($httpCode === 0) {
                $msg = "Could not connect to PowerDNS API at {$this->baseUrl}. Check the hostname and port.";
            }
            throw new RuntimeException("PowerDNS API error {$httpCode}: {$msg}");
        }

        return $decoded;
    }
}
