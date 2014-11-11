<?php

$maintFile = reset( array_splice( $argv, 1, 1 ) );
require_once( dirname( __FILE__ ) . "/$maintFile.php" );

$maintClass = ucfirst( $maintFile );
echo "Running class $maintClass in $maintFile.php\n";
require_once( RUN_MAINTENANCE_IF_MAIN );
