<?php
/**
 * Generate test-speech.wav with a simple sentence for audio transcription tests.
 * Run: php bin/generate-test-speech.php
 *
 * Uses formant synthesis to approximate "Hello, this is a test."
 * Output: assets/audio/test-speech.wav
 */

$sample_rate = 16000;

// Syllable definitions: [duration_sec, f1, f2, amplitude, pause_after]
// Formants approximate: h-eh-l-oh, th-ih-s, ih-z, ah, t-eh-s-t
$syllables = array(
	array( 0.15, 700, 1220, 5000 ),   // he-
	array( 0.12, 500, 1500, 4500 ),   // -llo
	array( 0.08, 0, 0, 0 ),          // pause
	array( 0.12, 400, 1600, 5000 ),   // thi-
	array( 0.10, 400, 1600, 4500 ),   // -s
	array( 0.08, 0, 0, 0 ),          // pause
	array( 0.10, 400, 1600, 4500 ),   // is
	array( 0.08, 0, 0, 0 ),          // pause
	array( 0.15, 700, 1220, 5000 ),   // a
	array( 0.08, 0, 0, 0 ),          // pause
	array( 0.12, 500, 1500, 5000 ),   // te-
	array( 0.15, 500, 1500, 4500 ),   // -st
);

$samples = '';
foreach ( $syllables as $syl ) {
	list( $dur, $f1, $f2, $amp ) = $syl;
	$n = (int) ( $sample_rate * $dur );
	for ( $i = 0; $i < $n; $i++ ) {
		$t = $i / $sample_rate;
		if ( $amp > 0 && $f1 > 0 ) {
			$env = sin( $t * M_PI / $dur );
			$s  = $env * $amp * ( 0.6 * sin( 2 * M_PI * $f1 * $t ) + 0.4 * sin( 2 * M_PI * $f2 * $t ) );
		} else {
			$s = 0;
		}
		$s = (int) max( -32768, min( 32767, $s ) );
		$samples .= pack( 'v', $s < 0 ? $s + 65536 : $s );
	}
}

$data_size = strlen( $samples );
$file_size = 36 + $data_size;

$header  = pack( 'A4V', 'RIFF', $file_size - 8 );
$header .= pack( 'A4', 'WAVE' );
$header .= pack( 'A4VvvVVvv', 'fmt ', 16, 1, 1, $sample_rate, $sample_rate * 2, 2, 16 );
$header .= pack( 'A4V', 'data', $data_size );
$wav = $header . $samples;

$dir = dirname( __DIR__ ) . '/assets/audio';
if ( ! is_dir( $dir ) ) {
	mkdir( $dir, 0755, true );
}
$path = $dir . '/test-speech.wav';
file_put_contents( $path, $wav );
echo "Generated: $path (" . strlen( $wav ) . " bytes)\n";
