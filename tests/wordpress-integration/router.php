<?php
// Used only by the ephemeral loopback server, never copied into WordPress.
require __DIR__ . '/bootstrap.php';
clfa_test_http( fn() => clfa_test_response() );
// Run WordPress's normal REST entrypoint, including serve_request and $_POST.
$GLOBALS['wp']->query_vars['rest_route'] = $_GET['rest_route'] ?? '';
rest_api_loaded();
