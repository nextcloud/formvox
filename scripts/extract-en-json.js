#!/usr/bin/env node
/**
 * Extract translatable strings into l10n/en.json — the canonical English source
 * that scripts/generate-pot.js turns into translationfiles/templates/formvox.pot.
 *
 * Why this exists: en.json is the source-of-truth for frontend (Vue/JS) strings,
 * but the Nextcloud Transifex sync-bot deletes it from the repo after each sync
 * (it is a build artifact, gitignored). It therefore has to be regenerated from
 * the code before every POT round. This script does that deterministically by
 * scanning the source for translation calls, so en.json is never hand-edited or
 * restored from git.
 *
 * What it scans:
 *   - src/**\/*.{vue,js}   t('formvox', '…')   this.t('…')   $t('…')   this.$t('…')
 *                          n('formvox', 's', 'p', …)   this.n(…)   $n('s', 'p', …)
 *   - lib/**\/*.php        ->t('…')             ->n('…', '…', …)            $l->t('…')
 *   - templates/**\/*.php  the same, plus the double-quoted forms ->t("…") / ->n("…", "…")
 *                          (PHP strings with a quote in them are written that way)
 *
 * Note: $t/$n are the Vue global aliases bound to the formvox appId in
 * src/main.js (translate('formvox', …)), so they carry NO 'formvox' first arg.
 *
 * Output structure (matches the NC bot's en.json exactly):
 *   { "translations": { "<msgid>": "<msgid>", "<singular>": ["<singular>", "<plural>"] } }
 *
 * Singular values map to themselves; plural entries map to a [singular, plural]
 * array — generate-pot.js already expects exactly this shape.
 *
 * This module also exports computeSourceStrings() so scripts/check-l10n-sync.js
 * can compute the exact same source-string set without a second copy of the
 * extraction regexes.
 *
 * Usage: node scripts/extract-en-json.js
 */

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..');
const OUT = path.join(ROOT, 'l10n', 'en.json');
const COUNT = path.join(ROOT, 'l10n', '.source-count.json');
const MANIFEST = path.join(ROOT, 'l10n', '.source-strings.json');

// A single-quoted JS/PHP string literal, allowing backslash escapes
// (e.g. \' and \\). Captured group 1 is the raw, still-escaped contents.
const STR = "'((?:[^'\\\\]|\\\\.)*)'";
// A double-quoted PHP string literal ("Question '%s' is required").
const DQ_STR = '"((?:[^"\\\\]|\\\\.)*)"';

// Translation-call patterns. Each yields singular (and plural where present).
//
// Two families:
//   1. t()/n()  — optional 'formvox' first arg. Forms: t('formvox','…'),
//      this.t('…'), $l->t('…'), ->t('…'), ->n('…','…').
//   2. $t()/$n() — Vue global aliases, string is the FIRST arg (no 'formvox').
//      Forms: $t('…'), this.$t('…'), $n('s','p',…).
//
// The (?<![\w$]) lookbehind before family 1 stops us matching the trailing `t`
// of an identifier (impor`t(`) or method (spli`t(`). Family 2 anchors on the
// literal `$t(`/`$n(` token, which can't be a false positive.
const T_PREFIX = `(?:this\\.|\\$\\w+->|->)?`;
// The app id itself is never a msgid: in t('formvox', SOME_MAP[key]) the
// optional app-id group would otherwise backtrack and capture 'formvox'.
const NOT_APPID = `(?!'formvox'\\s*,)`;

// Factory functions so every caller gets fresh, non-shared RegExp instances
// (a shared /g regex carries lastIndex state between files).
function makeRegexes() {
	return {
		T_RE: new RegExp(`(?<![\\w$])${T_PREFIX}t\\(\\s*(?:'formvox'\\s*,\\s*)?${NOT_APPID}${STR}`, 'g'),
		N_RE: new RegExp(`(?<![\\w$])${T_PREFIX}n\\(\\s*(?:'formvox'\\s*,\\s*)?${NOT_APPID}${STR}\\s*,\\s*${STR}`, 'g'),
		DT_RE: new RegExp(`(?:this\\.)?\\$t\\(\\s*${STR}`, 'g'),
		DN_RE: new RegExp(`(?:this\\.)?\\$n\\(\\s*${STR}\\s*,\\s*${STR}`, 'g'),
		// PHP only: ->t("…") / ->n("…", "…"). xgettext (the bot's PHP pass)
		// reads both quote styles, so the manifest must too.
		PT_RE: new RegExp(`->t\\(\\s*${DQ_STR}`, 'g'),
		PN_RE: new RegExp(`->n\\(\\s*${DQ_STR}\\s*,\\s*${DQ_STR}`, 'g'),
	};
}

// Un-escape a captured single-quoted literal into its real string value.
function unescapeLiteral(raw) {
	return raw.replace(/\\(['\\nt])/g, (_, ch) => {
		if (ch === 'n') return '\n';
		if (ch === 't') return '\t';
		return ch; // \' -> '   \\ -> \
	});
}

// Un-escape a captured double-quoted PHP literal (\$, \", \\, \n, \t).
function unescapePhpDouble(raw) {
	return raw.replace(/\\([$"\\nt])/g, (_, ch) => {
		if (ch === 'n') return '\n';
		if (ch === 't') return '\t';
		return ch;
	});
}

/**
 * The TRANSLATORS hint that the Nextcloud bot attaches to a call at `index`,
 * or null. Mirrors translationtool:
 *   - only the first translation call on a line gets a hint, together with
 *     calls nested in its arguments (the match may start a little earlier,
 *     at "$l->t(", or later, at "t(");
 *   - Vue <template>: the comment on the call's own line, else on the line
 *     directly above, if it contains TRANSLATORS (one line only);
 *   - scripts and PHP (xgettext --add-comments=TRANSLATORS): the // comment
 *     block directly above the line, from its TRANSLATORS line down.
 */
const HINT_TEXT = /TRANSLATORS[: ]*(.*)/;

// Where the call starting at `from` ends on its line (its closing parenthesis),
// so a call nested in its arguments counts as part of it, as for xgettext:
// t('formvox', 'Move to {section}', { section: x || t('formvox', 'Untitled') }).
function callEnd(line, from) {
	let depth = 0;
	let quote = null;
	for (let i = line.indexOf('(', from); i >= 0 && i < line.length; i++) {
		const ch = line[i];
		if (quote) {
			if (ch === '\\') i++;
			else if (ch === quote) quote = null;
		} else if (ch === "'" || ch === '"' || ch === '`') quote = ch;
		else if (ch === '(') depth++;
		else if (ch === ')' && --depth === 0) return i;
	}
	return line.length;
}
function hintFor(content, index, inTemplate, callRe) {
	const lineStart = content.lastIndexOf('\n', index - 1) + 1;
	let lineEnd = content.indexOf('\n', index);
	if (lineEnd === -1) lineEnd = content.length;
	const line = content.slice(lineStart, lineEnd);
	const before = content.slice(0, lineStart).split('\n');
	before.pop(); // the empty piece after the last newline
	const clean = (text) => text.replace(/-->.*$/, '').replace(/\*\/\s*$/, '').trim();

	// Only the first call on the line gets the comment: the bot's template regex
	// is greedy per line, and xgettext gives a comment to the first keyword only.
	callRe.lastIndex = 0;
	const first = callRe.exec(line);
	if (!first || index > lineStart + callEnd(line, first.index)) return null;

	if (inTemplate) {
		const own = line.match(HINT_TEXT);
		if (own) return clean(own[1]);
		const above = (before[before.length - 1] || '').match(HINT_TEXT);
		return above ? clean(above[1]) : null;
	}

	const block = [];
	for (let i = before.length - 1; i >= 0; i--) {
		// "<?php // …  ?>" in templates/ counts as a // comment too
		const c = before[i].trim().replace(/^<\?php\s+/, '').replace(/\s*\?>$/, '').match(/^(?:\/\/|\/\*|\*)\s?(.*)$/);
		if (!c) break;
		block.unshift(c[1]);
		if (HINT_TEXT.test(c[1])) {
			const first = block[0].match(HINT_TEXT)[1];
			return clean([first, ...block.slice(1)].join(' '));
		}
	}
	return null;
}

// Recursively collect files under dir matching one of the extensions.
function walk(dir, exts, acc = []) {
	let entries;
	try {
		entries = fs.readdirSync(dir, { withFileTypes: true });
	} catch (e) {
		return acc;
	}
	for (const entry of entries) {
		const full = path.join(dir, entry.name);
		if (entry.isDirectory()) {
			if (entry.name === 'node_modules' || entry.name === '.git') continue;
			walk(full, exts, acc);
		} else if (exts.some(ext => entry.name.endsWith(ext))) {
			acc.push(full);
		}
	}
	return acc;
}

/**
 * Scan src/ + lib/ + templates/ for translation calls and return the deterministic source
 * string set. Single source of truth for both the POT extractor (this file's
 * CLI) and the prebuild guard (check-l10n-sync.js).
 *
 * @returns {{ singulars: string[], plurals: [string,string][], files: string[], hints: Map<string, string[]> }}
 *   singulars and plurals are sorted (localeCompare); a string used in n() is a
 *   plural pair and never also appears in singulars.
 */
function computeSourceStrings() {
	const singulars = new Set();
	const plurals = new Map(); // singular -> plural
	const hints = new Map(); // msgid -> TRANSLATORS hints, in source order
	const addHint = (msgid, hint) => {
		if (!hint) return;
		const list = hints.get(msgid) || [];
		if (!list.includes(hint)) list.push(hint);
		hints.set(msgid, list);
	};

	const files = [
		...walk(path.join(ROOT, 'src'), ['.vue', '.js']),
		...walk(path.join(ROOT, 'lib'), ['.php']),
		...walk(path.join(ROOT, 'templates'), ['.php']),
	];

	for (const file of files) {
		const content = fs.readFileSync(file, 'utf8');
		const { T_RE, N_RE, DT_RE, DN_RE, PT_RE, PN_RE } = makeRegexes();
		const isPhp = file.endsWith('.php');
		const template = file.endsWith('.vue') ? content.match(/<template>([\s\S]*)<\/template>/) : null;
		const inTemplate = (i) => template !== null && i >= template.index && i < template.index + template[0].length;
		const callRe = isPhp ? /->[tn]\(/g : /(?<![\w$])(?:this\.)?[tn]\(\s*'formvox'/g;
		const hintAt = (i) => hintFor(content, i, inTemplate(i), callRe);

		// Plurals first so we can record the pairing; their singular also counts.
		let m;
		for (const [re, unescape] of [[N_RE, unescapeLiteral], [DN_RE, unescapeLiteral], ...(isPhp ? [[PN_RE, unescapePhpDouble]] : [])]) {
			while ((m = re.exec(content)) !== null) {
				const sing = unescape(m[1]);
				const plur = unescape(m[2]);
				if (sing) {
					plurals.set(sing, plur);
					addHint(sing, hintAt(m.index));
				}
			}
		}

		for (const [re, unescape] of [[T_RE, unescapeLiteral], [DT_RE, unescapeLiteral], ...(isPhp ? [[PT_RE, unescapePhpDouble]] : [])]) {
			while ((m = re.exec(content)) !== null) {
				const s = unescape(m[1]);
				if (s) {
					singulars.add(s);
					addHint(s, hintAt(m.index));
				}
			}
		}
	}

	// Plurals take precedence: a string used in n() is an array entry, not a bare
	// singular, so drop it from the singular set to avoid a duplicate key clash.
	for (const sing of plurals.keys()) {
		singulars.delete(sing);
	}

	const sortedSingulars = [...singulars].sort((a, b) => a.localeCompare(b));
	const sortedPlurals = [...plurals.keys()]
		.sort((a, b) => a.localeCompare(b))
		.map(s => [s, plurals.get(s)]);

	return { singulars: sortedSingulars, plurals: sortedPlurals, files, hints };
}

/**
 * The canonical, order-stable list of every source msgid (singulars + the
 * singular form of each plural), and its sha256. This is what the manifest and
 * the guard compare on — durable across POT deletes by the bot.
 *
 * @param {{singulars:string[], plurals:[string,string][]}} src
 * @returns {{ msgids: string[], sha256: string, count: number }}
 */
function sourceStringManifest(src) {
	const msgids = [...src.singulars, ...src.plurals.map(([s]) => s)]
		.sort((a, b) => a.localeCompare(b));
	const sha256 = crypto.createHash('sha256')
		.update(JSON.stringify(msgids))
		.digest('hex');
	return { msgids, sha256, count: msgids.length };
}

module.exports = { computeSourceStrings, sourceStringManifest };

// ---- CLI ----
if (require.main === module) {
	const src = computeSourceStrings();

	// Build the translations object, sorted for deterministic output.
	const translations = {};
	for (const s of src.singulars) {
		translations[s] = s;
	}
	for (const [s, p] of src.plurals) {
		translations[s] = [s, p];
	}

	const out = { translations, pluralForm: 'nplurals=2; plural=(n != 1);' };
	fs.mkdirSync(path.dirname(OUT), { recursive: true });
	fs.writeFileSync(OUT, JSON.stringify(out, null, 4) + '\n');

	// Committed count file — the runtime denominator for the admin "translation
	// coverage" percentage (l10n/en.json is a gitignored build artifact absent on
	// the server, so the total lives here instead).
	const sourceStrings = src.singulars.length + src.plurals.length;
	fs.writeFileSync(COUNT, JSON.stringify({ sourceStrings }, null, 4) + '\n');

	// Committed source-string manifest — the durable record of exactly which
	// msgids the Transifex bot has been given. scripts/check-l10n-sync.js fails
	// the build if the code's set no longer matches this, i.e. strings were added
	// without pushing them to Transifex (npm run l10n:push). The full sorted list
	// is stored (not just the hash) so every git diff shows what entered/left.
	const manifest = sourceStringManifest(src);
	fs.writeFileSync(MANIFEST, JSON.stringify({
		count: manifest.count,
		sha256: manifest.sha256,
		strings: manifest.msgids,
	}, null, 4) + '\n');

	console.log(`Extracted ${src.singulars.length} singular + ${src.plurals.length} plural strings`);
	console.log(`Scanned ${src.files.length} files`);
	console.log(`Wrote ${path.relative(ROOT, OUT)}`);
	console.log(`Wrote ${path.relative(ROOT, COUNT)} (sourceStrings=${sourceStrings})`);
	console.log(`Wrote ${path.relative(ROOT, MANIFEST)} (count=${manifest.count}, sha256=${manifest.sha256.slice(0, 12)}…)`);
}
