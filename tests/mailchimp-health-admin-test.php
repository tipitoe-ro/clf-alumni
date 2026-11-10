<?php
// Synthetic rendering only; no WordPress server, credentials or API calls.
if ( PHP_SAPI !== 'cli' ) { exit; }
ob_start();
require __DIR__ . '/mailchimp-render.php';
$html = ob_get_clean();
check( str_contains( $html, 'Webhook health' ) && str_contains( $html, 'None recorded yet' ), 'New installations show empty health history' );
check( str_contains( $html, '0 total since health tracking began' ), 'New installations show zero verification failures' );
$options['clfa_mc_webhook_last_accepted'] = 1700000000;
$options['clfa_mc_webhook_verification'] = array( 'failures' => 3, 'last_failure' => 1700000060 );
$options['clfa_mailchimp']['enabled'] = false;
ob_start();
clfa_mc_admin_page();
$html = ob_get_clean();
check( str_contains( $html, '2023-11-14 22:13:20' ) && str_contains( $html, '2023-11-14 22:14:20' ), 'Delivery and failure times render in UTC' );
check( str_contains( $html, '3 total since health tracking began' ) && str_contains( $html, 'Verification failures have occurred' ), 'Historical verification failures are visible' );
check( str_contains( $html, 'Updates are paused or unconfigured' ), 'Paused connection is visible' );
$GLOBALS['test_admin_access'] = false;
ob_start();
clfa_mc_admin_page();
check( ob_get_clean() === '', 'Non-administrators cannot see health diagnostics or settings' );
