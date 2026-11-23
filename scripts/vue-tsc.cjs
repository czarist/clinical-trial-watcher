/**
 * Runs vue-tsc on the TypeScript 6 compiler.
 *
 * The project uses TypeScript 7 (native compiler), which no longer ships the JavaScript
 * compiler API that vue-tsc needs to check .vue files. @typescript/typescript6 is the
 * official TypeScript 6 package for this case; it keeps the full compiler in its
 * @typescript/old dependency. Remove this script once vue-tsc supports TypeScript 7.
 *
 * Usage: node scripts/vue-tsc.cjs --noEmit
 */
const path = require('node:path');

const typescript6 = path.dirname(require.resolve('@typescript/typescript6/package.json'));
const tsc = require.resolve('@typescript/old/lib/tsc', { paths: [typescript6] });

require('vue-tsc').run(tsc);
