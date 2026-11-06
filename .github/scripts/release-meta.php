<?php
/**
 * Release helper, run by the Release workflow (not part of the plugin).
 *
 *   php release-meta.php notes   <version>                 Prints this version's changelog entry from readme.txt.
 *   php release-meta.php update  <version> <repo> <tag>   Prints update.json, the file the updater reads.
 *
 * Exits with an error when readme.txt has no changelog entry for the version.
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$root   = dirname( __DIR__, 2 );
$mode   = $argv[1] ?? '';
$ver    = $argv[2] ?? '';
$readme = str_replace( "\r", '', (string) file_get_contents( $root . '/readme.txt' ) );

// "== Section ==" blocks, keyed like the plugin's own parser (CPW_Updater::parse_readme()).
$sections = array();
$parts    = preg_split( '/^==\s*(.+?)\s*==\s*$/m', $readme, -1, PREG_SPLIT_DELIM_CAPTURE );
for ( $i = 1, $n = count( $parts ); $i < $n; $i += 2 ) {
	$key              = preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $parts[ $i ] ) );
	$sections[ $key ] = trim( $parts[ $i + 1 ] ?? '' );
}

// This version's "= x.y.z =" entry in the changelog.
$notes = '';
if ( preg_match( '/^=\s*' . preg_quote( $ver, '/' ) . '\s*=\s*$(.*?)(?=^=\s*[^=]+?\s*=\s*$|\z)/ms', $sections['changelog'] ?? '', $m ) ) {
	$notes = trim( $m[1] );
}
if ( '' === $notes ) {
	fwrite( STDERR, "readme.txt has no changelog entry \"= $ver =\".\n" );
	exit( 1 );
}

if ( 'notes' === $mode ) {
	echo $notes, "\n";
	exit( 0 );
}

if ( 'update' === $mode ) {
	$repo   = $argv[3] ?? '';
	$tag    = $argv[4] ?? '';
	$header = static function ( $label ) use ( $readme ) {
		return preg_match( '/^' . preg_quote( $label, '/' ) . ':\s*(.+)$/mi', $readme, $m ) ? trim( $m[1] ) : '';
	};
	echo json_encode(
		array(
			'version'      => $ver,
			'tag'          => $tag,
			'package'      => "https://github.com/$repo/releases/download/$tag/crypto-payments-woo.zip",
			'requires'     => $header( 'Requires at least' ),
			'tested'       => $header( 'Tested up to' ),
			'requires_php' => $header( 'Requires PHP' ),
			'published'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'notes'        => $notes,
			'sections'     => $sections,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	), "\n";
	exit( 0 );
}

fwrite( STDERR, "Usage: php release-meta.php notes|update <version> [<repo> <tag>]\n" );
exit( 1 );
