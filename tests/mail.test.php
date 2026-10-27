<?php
// Test al corpului e-mailului de notificare (TFLG_Cron::build_notification_body / mail_safe_url).
// Rulare: php tests/mail.test.php  — extrage cele două metode din plugin și le rulează cu stub-uri minime
// pentru __() și sprintf; NU pornește WordPress (nu poate testa wp_mail() / cron / baza de date).
$src = file_get_contents( __DIR__ . '/../trade-free-link-guardian.php' );
function grab( $src, $name ) {
	$p = strpos( $src, 'public static function ' . $name . '(' );
	if ( false === $p ) { fwrite( STDERR, "lipsește $name\n" ); exit( 1 ); }
	$i = strpos( $src, '{', $p ); $d = 0; $n = strlen( $src );
	for ( $k = $i; $k < $n; $k++ ) {
		if ( '{' === $src[ $k ] ) { $d++; }
		elseif ( '}' === $src[ $k ] ) { $d--; if ( 0 === $d ) { return substr( $src, $p, $k - $p + 1 ); } }
	}
	exit( 1 );
}
function __( $s, $d = '' ) { return $s; }
eval( 'class TFLG_Cron_Test { ' . grab( $src, 'mail_safe_url' ) . "\n" . grab( $src, 'build_notification_body' ) . ' }' );
$fail = 0;
function ok( $name, $c ) { global $fail; echo ( $c ? '  ok  ' : 'FAIL  ' ) . $name . "\n"; if ( ! $c ) { $fail++; } }
$admin = 'https://example.org/wp-admin/admin.php?page=tflg-links&status=broken';

$b = TFLG_Cron_Test::build_notification_body( 2, 2, $admin, array( 'https://a.test/x', 'https://b.test/y' ) );
ok( 'conține numerele și ambele URL-uri', false !== strpos( $b, '2 new broken' ) && false !== strpos( $b, '- https://a.test/x' ) && false !== strpos( $b, '- https://b.test/y' ) );
ok( 'conține legătura către listă', false !== strpos( $b, $admin ) );
ok( 'fără „... and N more” când sunt toate listate', false === strpos( $b, 'more.' ) );

$many = array(); for ( $i = 1; $i <= 15; $i++ ) { $many[] = 'https://s.test/' . $i; }
$b = TFLG_Cron_Test::build_notification_body( 15, 15, $admin, $many, 10 );
ok( 'maximum 10 URL-uri listate', 10 === substr_count( $b, "\n- " ) + ( 0 === strpos( $b, '- ' ) ? 1 : 0 ) );
ok( 'spune câte nu sunt listate (5)', false !== strpos( $b, '... and 5 more.' ) );

$b = TFLG_Cron_Test::build_notification_body( 1, 1, $admin, array( "https://x.test/a\r\nBcc: evil@example.org", "https://y.test/\0b" ) );
ok( 'CR/LF și NUL sunt eliminate (fără linie injectată)', false === strpos( $b, "\r" ) && false === strpos( $b, "\nBcc:" ) && false === strpos( $b, "\0" ) );
$long = 'https://l.test/' . str_repeat( 'a', 500 );
ok( 'URL foarte lung este scurtat la 200', 200 === strlen( TFLG_Cron_Test::mail_safe_url( $long ) ) && '...' === substr( TFLG_Cron_Test::mail_safe_url( $long ), -3 ) );
$b = TFLG_Cron_Test::build_notification_body( 3, 3, $admin, array() );
ok( 'fără URL-uri: se păstrează formatul vechi (numere + legătură)', false === strpos( $b, 'first' ) && false !== strpos( $b, 'Review them here' ) );
echo $fail ? "\n$fail eșuate\n" : "\ntoate reușite\n";
exit( $fail ? 1 : 0 );
