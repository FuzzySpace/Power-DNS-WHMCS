<?php

/**
 * Record Templates – PowerDNS Premium
 *
 * Pre-built DNS record sets for common providers and use-cases.
 * Each template returns a list of record definitions that can be
 * previewed in the UI and applied via PowerDNSAPI::batchPatch().
 *
 * Record format: ['name','type','ttl','content']
 *   - 'name' uses '{{ZONE}}' as a placeholder for the actual zone apex
 *   - MX priority is embedded in 'content' as per PowerDNS format
 *   - TXT content is already quoted
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class RecordTemplates
{
    /**
     * Return the registry of all available templates.
     *
     * @return array  Keys are template IDs; values are metadata arrays.
     */
    public static function catalog()
    {
        return [
            'google_workspace' => [
                'id'          => 'google_workspace',
                'name'        => 'Google Workspace (Gmail)',
                'description' => 'MX records plus SPF TXT for Google Workspace email routing.',
                'icon'        => 'fa-google',
                'affects'     => ['MX', 'TXT'],
                'warning'     => 'This will replace all existing MX records and overwrite any existing SPF TXT record on the zone apex.',
            ],
            'microsoft_365' => [
                'id'          => 'microsoft_365',
                'name'        => 'Microsoft 365 (Exchange Online)',
                'description' => 'MX, SPF, Autodiscover CNAME, and SIP records for Microsoft 365.',
                'icon'        => 'fa-windows',
                'affects'     => ['MX', 'TXT', 'CNAME'],
                'warning'     => 'This will replace all existing MX records and may overwrite existing CNAME / TXT records.',
            ],
            'zoho_mail' => [
                'id'          => 'zoho_mail',
                'name'        => 'Zoho Mail',
                'description' => 'MX records and SPF for Zoho Mail.',
                'icon'        => 'fa-envelope',
                'affects'     => ['MX', 'TXT'],
                'warning'     => 'This will replace all existing MX records.',
            ],
            'cloudflare_proxy' => [
                'id'          => 'cloudflare_proxy',
                'name'        => 'Cloudflare Nameservers',
                'description' => 'Remove existing NS records and replace with placeholder Cloudflare NS.',
                'icon'        => 'fa-cloud',
                'affects'     => ['NS'],
                'warning'     => 'Replaces apex NS records. Do not use unless you are delegating to Cloudflare.',
            ],
            'github_pages' => [
                'id'          => 'github_pages',
                'name'        => 'GitHub Pages',
                'description' => 'A records pointing to GitHub Pages IPs, plus www CNAME.',
                'icon'        => 'fa-github',
                'affects'     => ['A', 'CNAME'],
                'warning'     => 'Replaces apex A records and www CNAME.',
            ],
            'blank' => [
                'id'          => 'blank',
                'name'        => 'Clear All User Records',
                'description' => 'Delete all A, AAAA, MX, TXT, CNAME, SRV, CAA records. SOA and NS are preserved.',
                'icon'        => 'fa-trash',
                'affects'     => ['A', 'AAAA', 'MX', 'TXT', 'CNAME', 'SRV', 'CAA'],
                'warning'     => 'This permanently deletes all user-managed records for this zone.',
            ],
        ];
    }

    /**
     * Return the record definitions for a specific template.
     *
     * '{{ZONE}}' in 'name' fields is replaced with $zone (FQDN with dot).
     *
     * @param  string $templateId
     * @param  string $zone        Zone FQDN (with trailing dot)
     * @return array  ['records' => [...], 'delete_types' => [...]]
     *                delete_types: record types to wipe before adding
     */
    public static function get($templateId, $zone)
    {
        $zone = rtrim($zone, '.') . '.';
        $raw  = self::definitions($templateId);

        if (empty($raw)) {
            return ['records' => [], 'delete_types' => []];
        }

        // Replace {{ZONE}} placeholder
        $records = array_map(function ($r) use ($zone) {
            $r['name']    = str_replace('{{ZONE}}', $zone, $r['name']);
            $r['content'] = str_replace('{{ZONE}}', rtrim($zone, '.'), $r['content']);
            return $r;
        }, $raw['records']);

        return [
            'records'      => $records,
            'delete_types' => $raw['delete_types'] ?? [],
        ];
    }

    /**
     * Convert template records to PowerDNS PATCH RRsets.
     *
     * Records with the same name+type are merged automatically.
     *
     * @param  array $records From get()['records']
     * @return array RRsets ready for PowerDNSAPI::batchPatch()
     */
    public static function toRRsets(array $records)
    {
        $grouped = [];
        foreach ($records as $rec) {
            $key = $rec['name'] . '|' . $rec['type'];
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'name'       => $rec['name'],
                    'type'       => $rec['type'],
                    'ttl'        => $rec['ttl'],
                    'changetype' => 'REPLACE',
                    'records'    => [],
                ];
            }
            $grouped[$key]['records'][] = ['content' => $rec['content'], 'disabled' => false];
        }
        return array_values($grouped);
    }

    /**
     * Build DELETE RRsets for all records of the given types at the zone apex.
     * Used to wipe existing records before applying a template.
     *
     * @param  array  $rrsets      Current zone RRsets from getZone()
     * @param  string $zone        Zone FQDN
     * @param  array  $deleteTypes Record types to delete
     * @return array  DELETE RRsets
     */
    public static function buildDeleteRRsets(array $rrsets, $zone, array $deleteTypes)
    {
        $zone   = rtrim($zone, '.') . '.';
        $result = [];

        foreach ($rrsets as $rr) {
            if (!in_array(strtoupper($rr['type']), array_map('strtoupper', $deleteTypes), true)) {
                continue;
            }
            $result[] = [
                'name'       => $rr['name'],
                'type'       => $rr['type'],
                'changetype' => 'DELETE',
                'records'    => [],
            ];
        }

        return $result;
    }

    // =========================================================================
    // Raw template definitions
    // =========================================================================

    private static function definitions($templateId)
    {
        switch ($templateId) {

            case 'google_workspace':
                return [
                    'delete_types' => ['MX'],
                    'records' => [
                        ['name' => '{{ZONE}}', 'type' => 'MX',  'ttl' => 3600, 'content' => '1 aspmx.l.google.com.'],
                        ['name' => '{{ZONE}}', 'type' => 'MX',  'ttl' => 3600, 'content' => '5 alt1.aspmx.l.google.com.'],
                        ['name' => '{{ZONE}}', 'type' => 'MX',  'ttl' => 3600, 'content' => '5 alt2.aspmx.l.google.com.'],
                        ['name' => '{{ZONE}}', 'type' => 'MX',  'ttl' => 3600, 'content' => '10 alt3.aspmx.l.google.com.'],
                        ['name' => '{{ZONE}}', 'type' => 'MX',  'ttl' => 3600, 'content' => '10 alt4.aspmx.l.google.com.'],
                        ['name' => '{{ZONE}}', 'type' => 'TXT', 'ttl' => 3600, 'content' => '"v=spf1 include:_spf.google.com ~all"'],
                    ],
                ];

            case 'microsoft_365':
                return [
                    'delete_types' => ['MX'],
                    'records' => [
                        // MX – tenant-specific; use placeholder that admin can customize
                        ['name' => '{{ZONE}}',             'type' => 'MX',    'ttl' => 3600, 'content' => '0 {{ZONE}}.mail.protection.outlook.com.'],
                        // SPF
                        ['name' => '{{ZONE}}',             'type' => 'TXT',   'ttl' => 3600, 'content' => '"v=spf1 include:spf.protection.outlook.com -all"'],
                        // Autodiscover
                        ['name' => 'autodiscover.{{ZONE}}','type' => 'CNAME', 'ttl' => 3600, 'content' => 'autodiscover.outlook.com.'],
                        // Teams / Skype SIP
                        ['name' => 'sip.{{ZONE}}',         'type' => 'CNAME', 'ttl' => 3600, 'content' => 'sipdir.online.lync.com.'],
                        ['name' => 'lyncdiscover.{{ZONE}}','type' => 'CNAME', 'ttl' => 3600, 'content' => 'webdir.online.lync.com.'],
                    ],
                ];

            case 'zoho_mail':
                return [
                    'delete_types' => ['MX'],
                    'records' => [
                        ['name' => '{{ZONE}}', 'type' => 'MX',  'ttl' => 3600, 'content' => '10 mx.zoho.com.'],
                        ['name' => '{{ZONE}}', 'type' => 'MX',  'ttl' => 3600, 'content' => '20 mx2.zoho.com.'],
                        ['name' => '{{ZONE}}', 'type' => 'MX',  'ttl' => 3600, 'content' => '50 mx3.zoho.com.'],
                        ['name' => '{{ZONE}}', 'type' => 'TXT', 'ttl' => 3600, 'content' => '"v=spf1 include:zoho.com ~all"'],
                    ],
                ];

            case 'cloudflare_proxy':
                return [
                    'delete_types' => [],
                    'records' => [
                        // Placeholder: real NS values are given after Cloudflare activation
                        ['name' => '{{ZONE}}', 'type' => 'NS', 'ttl' => 86400, 'content' => 'alex.ns.cloudflare.com.'],
                        ['name' => '{{ZONE}}', 'type' => 'NS', 'ttl' => 86400, 'content' => 'linda.ns.cloudflare.com.'],
                    ],
                ];

            case 'github_pages':
                return [
                    'delete_types' => ['A'],
                    'records' => [
                        ['name' => '{{ZONE}}',      'type' => 'A',     'ttl' => 3600, 'content' => '185.199.108.153'],
                        ['name' => '{{ZONE}}',      'type' => 'A',     'ttl' => 3600, 'content' => '185.199.109.153'],
                        ['name' => '{{ZONE}}',      'type' => 'A',     'ttl' => 3600, 'content' => '185.199.110.153'],
                        ['name' => '{{ZONE}}',      'type' => 'A',     'ttl' => 3600, 'content' => '185.199.111.153'],
                        ['name' => 'www.{{ZONE}}',  'type' => 'CNAME', 'ttl' => 3600, 'content' => '{{ZONE}}.'],
                    ],
                ];

            case 'blank':
                // No new records – just delete everything
                return [
                    'delete_types' => ['A', 'AAAA', 'MX', 'TXT', 'CNAME', 'SRV', 'CAA', 'ALIAS', 'PTR'],
                    'records'      => [],
                ];

            default:
                return null;
        }
    }
}
