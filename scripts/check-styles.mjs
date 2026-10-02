import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { compile } from 'sass';

const source = fileURLToPath(new URL('../resources/scss/app.scss', import.meta.url));
const target = new URL('../public/assets/css/app.css', import.meta.url);
const compiled = compile(source, { style: 'expanded', charset: false }).css;
const saved = await readFile(target, 'utf8');

if (saved.trimEnd() !== compiled.trimEnd()) {
    console.error('CSS is out of date. Run npm run styles:build and commit the generated file.');
    process.exitCode = 1;
} else {
    console.log('Compiled CSS matches the SCSS sources.');
}
