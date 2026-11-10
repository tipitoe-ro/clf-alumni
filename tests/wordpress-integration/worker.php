<?php
require __DIR__ . '/bootstrap.php';
$mode = $argv[1] ?? '';
if ( 'hold' === $mode ) {
	clfa_test_http( function () use ( $root ) {
		file_put_contents( "$root/worker-ready", 'ready' );
		$deadline = microtime( true ) + 15;
		while ( ! is_file( "$root/worker-release" ) ) {
			if ( microtime( true ) > $deadline ) {
				throw new RuntimeException( 'Timed out waiting for lock contention test.' );
			}
			usleep( 10000 );
		}
		return clfa_test_response();
	} );
} elseif ( 'blocked' !== $mode ) {
	throw new RuntimeException( 'Unknown worker mode.' );
}
// In blocked mode the default HTTP trap must never be reached.
clfa_mc_worker();
echo "Worker finished\n";
