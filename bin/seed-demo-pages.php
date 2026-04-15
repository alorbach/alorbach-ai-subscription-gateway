<?php
/**
 * Seed demo pages for local browser verification.
 *
 * Run with wp-env / WP-CLI, for example:
 * wp eval-file wp-content/plugins/alorbach-ai-subscription-gateway/bin/seed-demo-pages.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This script must run inside a loaded WordPress context.\n" );
	exit( 1 );
}

$admin = get_user_by( 'login', 'admin' );
if ( ! $admin ) {
	fwrite( STDERR, "Could not resolve the admin user.\n" );
	exit( 1 );
}

wp_set_current_user( (int) $admin->ID );

$current_structure = (string) get_option( 'permalink_structure', '' );
if ( '/%postname%/' !== $current_structure ) {
	update_option( 'permalink_structure', '/%postname%/' );
	flush_rewrite_rules();
}

$result = \Alorbach\AIGateway\Admin\Admin_Demo_Defaults::create_sample_pages();
if ( empty( $result['success'] ) ) {
	fwrite( STDERR, ( $result['message'] ?? 'Could not create sample pages.' ) . "\n" );
	exit( 1 );
}

$expected_slugs = array(
	'image-generator',
	'ai-chat-demo',
	'audio-transcription',
	'video-generator',
);

$missing = array();
foreach ( $expected_slugs as $slug ) {
	$page = get_page_by_path( $slug, OBJECT, 'page' );
	if ( ! $page || 'publish' !== $page->post_status ) {
		$missing[] = $slug;
	}
}

if ( ! empty( $missing ) ) {
	fwrite( STDERR, 'Missing seeded demo pages: ' . implode( ', ', $missing ) . "\n" );
	exit( 1 );
}

echo "Demo pages seeded successfully.\n";
foreach ( $result['links'] as $link ) {
	if ( empty( $link['title'] ) || empty( $link['url'] ) ) {
		continue;
	}
	echo '- ' . $link['title'] . ': ' . $link['url'] . "\n";
}

exit( 0 );
