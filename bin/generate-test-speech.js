/**
 * Generate test-speech.wav with real speech using text2wav (espeak-ng).
 * Run: npm run generate:test-speech (from project root)
 *
 * Requires: npm install (text2wav is a devDependency)
 */

const fs = require('fs');
const path = require('path');

const PHRASE = 'Hello, this is a test.';
const OUT_DIR = path.join(__dirname, '..', 'assets', 'audio');
const OUT_PATH = path.join(OUT_DIR, 'test-speech.wav');

async function main() {
  let text2wav;
  try {
    text2wav = require('text2wav');
  } catch (e) {
    console.error('text2wav not found. Run: npm install');
    process.exit(1);
  }

  const wav = await text2wav(PHRASE, { voice: 'en', speed: 150 });
  if (!fs.existsSync(OUT_DIR)) {
    fs.mkdirSync(OUT_DIR, { recursive: true });
  }
  fs.writeFileSync(OUT_PATH, Buffer.from(wav));
  console.log('Generated:', OUT_PATH, '(' + wav.length + ' bytes)');
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
