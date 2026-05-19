<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

// Heading
$_['heading_title']                   = 'OpenSalesTax';

// Text
$_['text_extension']                  = 'Extensions';
$_['text_success']                    = 'Success: You have modified the OpenSalesTax settings.';
$_['text_edit']                       = 'Edit OpenSalesTax';
$_['text_disclaimer']                 = 'Tax calculations are provided as-is for convenience. The merchant is solely responsible for tax-collection accuracy and remittance to the appropriate jurisdictions. Verify against your state Department of Revenue before remitting.';

// Entry
$_['entry_status']                    = 'Enabled';
$_['entry_base_url']                  = 'Engine base URL';
$_['entry_api_key']                   = 'API key (optional)';
$_['entry_timeout_seconds']           = 'HTTP timeout (seconds)';
$_['entry_tls_verify']                = 'Verify TLS certificate';
$_['entry_allow_private_nets']        = 'Allow private network engines (advanced)';
$_['entry_fail_hard']                 = 'Block checkout on engine error';
$_['entry_cache_ttl_seconds']         = 'Cache TTL (seconds)';
$_['entry_exempt_groups']             = 'Exempt customer groups';
$_['entry_per_jurisdiction_lines']    = 'Show tax breakdown per jurisdiction';
$_['entry_nexus_states']              = 'Nexus states (comma-separated)';

// Help
$_['help_base_url']                   = 'Base URL of your self-hosted OpenSalesTax engine, e.g. https://ost.example.com';
$_['help_api_key']                    = 'Bearer token if your engine requires authentication. Leave blank for unauthenticated engines.';
$_['help_tls_verify']                 = 'Strongly recommended ON. Disable only for engines with self-signed certificates.';
$_['help_allow_private_nets']         = 'Permit RFC1918 / loopback / link-local hosts. Required if your engine runs on the same LAN as OpenCart.';
$_['help_fail_hard']                  = 'When ON, an unreachable engine blocks checkout. When OFF (default), OpenCart\'s built-in tax handles the cart and a warning is logged.';
$_['help_exempt_groups']              = 'Comma-separated OpenCart customer-group IDs that bypass real-time tax calculation (e.g. wholesale, nonprofit). OpenCart\'s built-in tax flow handles them. Leave blank for no exemptions.';
$_['help_per_jurisdiction_lines']     = 'When ON, the checkout cart shows a separate total line for each jurisdiction (state / county / city / special). When OFF (default), a single aggregate "Sales Tax" line.';
$_['help_nexus_states']               = 'Comma-separated list of US 2-letter state codes (e.g. "MN,WI,IA") the merchant has nexus in. When set, the engine is only called for carts shipping to these states. Carts to any other state short-circuit to OpenCart\'s built-in tax tables (typically: no tax). Leave blank to call the engine for every US/USD cart (pre-v0.3 behavior). Missing or unresolvable destination state with the filter active is fail-closed.';

// Per-jurisdiction line titles (used on both admin + catalog sides; %s receives the jurisdiction name)
$_['title_state_tax']                 = '%s State Tax';
$_['title_county_tax']                = '%s County Tax';
$_['title_city_tax']                  = '%s City Tax';
$_['title_special_tax']               = '%s District Tax';

// Button
$_['button_test_connection']          = 'Test Connection';

// Text
$_['text_testing']                    = 'Contacting the OpenSalesTax engineâ€¦';
$_['text_test_ok']                    = 'Engine reachable.';
$_['text_test_fail']                  = 'Could not reach the OpenSalesTax engine.';

// Error
$_['error_permission']                = 'Warning: You do not have permission to modify OpenSalesTax!';
$_['error_test_no_url']               = 'Enter an engine base URL before testing the connection.';
