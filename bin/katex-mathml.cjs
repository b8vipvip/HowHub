'use strict';
/**
 * Offline renderer. Not a web server. The only input is a small TeX file
 * prepared by HowHub. No external URL, HTML, SVG or JavaScript is accepted.
 * KaTeX itself runs with trust:false and bounded expansion/size.
 */
const fs = require('node:fs');
const path = require('node:path');
const katex = require(path.join(__dirname, '..', 'node_modules', 'katex'));

const file = process.argv[2];
try {
  if (typeof file !== 'string' || !file.startsWith('/tmp/hh-tex-') ||
      file.length > 220 || !fs.statSync(file).isFile()) {
    throw new Error('invalid input path');
  }
  const tex = fs.readFileSync(file, 'utf8');
  if (!tex || Buffer.byteLength(tex, 'utf8') > 1900) {
    throw new Error('input size exceeded');
  }
  const output = katex.renderToString(tex, {
    output: 'mathml',
    displayMode: true,
    trust: false,
    throwOnError: true,
    maxSize: 8,
    maxExpand: 64,
    strict: 'error',
    macros: {},
  });
  if (!output || Buffer.byteLength(output, 'utf8') > 35000) {
    throw new Error('output size exceeded');
  }
  process.stdout.write(output);
} catch (err) {
  process.stderr.write('KaTeX rejected input\n');
  process.exitCode = 1;
}
