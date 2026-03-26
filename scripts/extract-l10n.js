#!/usr/bin/env node
/**
 * Nextcloud L10n String Extractor for FormVox
 * Extracts translatable strings from Vue components, JS files, and PHP files
 * Generates both .json and .js translation files
 */

const fs = require('fs');
const path = require('path');

const CONFIG = {
  srcDirs: ['src', 'js'],
  phpDirs: ['lib', 'templates'],
  extensions: ['.vue', '.js', '.ts'],
  phpExtensions: ['.php'],
  l10nDir: 'l10n',
  sourceLocale: 'en',
  targetLocales: ['nl', 'de', 'fr'],
  needsTranslationPrefix: '__NEEDS_TRANSLATION__',
  // Strings that are extracted but shouldn't be translated (code artifacts)
  ignorePatterns: [
    /^update:/,
    /^upload-image$/,
    /^__VUE_/,
    /^h[1-6]$/,
    /^div$/,
    /^span$/,
  ],
};

// JS/Vue patterns
const JS_PATTERNS = [
  // t('formvox', 'text') or t('formvox', 'text', { vars })
  /\bt\(\s*['"]formvox['"]\s*,\s*['"]([^'"]+)['"]/g,
  // t('text') for simple cases (from l10n.js wrapper)
  /\bt\(\s*['"]([^'"]+)['"]\s*[,)]/g,
  // $t('formvox', 'text') in Vue templates
  /\$t\(\s*['"]formvox['"]\s*,\s*['"]([^'"]+)['"]/g,
  // n('formvox', 'singular', 'plural', count)
  /n\(\s*['"]formvox['"]\s*,\s*['"]([^'"]+)['"],\s*['"]([^'"]+)['"]/g,
];

// PHP patterns
const PHP_PATTERNS = [
  // $l->t('text') or $this->l->t('text')
  /->t\(\s*['"]([^'"]+)['"]/g,
  // $l10n->t('text')
  /\$l10n->t\(\s*['"]([^'"]+)['"]/g,
];

/**
 * Extract translation keys from a JS/Vue file
 */
function extractKeysFromJsFile(filePath) {
  const content = fs.readFileSync(filePath, 'utf-8');
  const keys = new Map();

  for (const pattern of JS_PATTERNS) {
    pattern.lastIndex = 0;
    let match;
    while ((match = pattern.exec(content)) !== null) {
      const key = match[1];
      // Skip if it looks like a variable or import path
      if (key.includes('/') && key.includes('.') && !key.includes(' ')) continue;
      if (key.startsWith('@')) continue;
      if (key === 'formvox') continue;
      keys.set(key, key);
      // For n() calls, also capture plural form
      if (match[2]) {
        keys.set(match[2], match[2]);
      }
    }
  }

  return keys;
}

/**
 * Extract translation keys from a PHP file
 */
function extractKeysFromPhpFile(filePath) {
  const content = fs.readFileSync(filePath, 'utf-8');
  const keys = new Map();

  for (const pattern of PHP_PATTERNS) {
    pattern.lastIndex = 0;
    let match;
    while ((match = pattern.exec(content)) !== null) {
      keys.set(match[1], match[1]);
    }
  }

  return keys;
}

/**
 * Recursively get all files with given extensions
 */
function getAllFiles(dirs, extensions) {
  const files = [];

  function walkDir(dir) {
    if (!fs.existsSync(dir)) return;
    const entries = fs.readdirSync(dir, { withFileTypes: true });
    for (const entry of entries) {
      const fullPath = path.join(dir, entry.name);
      if (entry.isDirectory() && entry.name !== 'node_modules' && entry.name !== '.git') {
        walkDir(fullPath);
      } else if (extensions.some(ext => entry.name.endsWith(ext))) {
        files.push(fullPath);
      }
    }
  }

  dirs.forEach(walkDir);
  return files;
}

/**
 * Load Nextcloud l10n JSON file
 */
function loadL10nFile(locale) {
  const filePath = path.join(CONFIG.l10nDir, `${locale}.json`);
  if (!fs.existsSync(filePath)) {
    return { translations: {}, pluralForm: 'nplurals=2; plural=(n != 1);' };
  }
  try {
    return JSON.parse(fs.readFileSync(filePath, 'utf-8'));
  } catch (e) {
    console.warn(`Warning: Could not parse ${filePath}`);
    return { translations: {}, pluralForm: 'nplurals=2; plural=(n != 1);' };
  }
}

/**
 * Save Nextcloud l10n JSON file (sorted alphabetically)
 */
function saveL10nFile(locale, data) {
  const filePath = path.join(CONFIG.l10nDir, `${locale}.json`);

  const sortedTranslations = Object.keys(data.translations).sort().reduce((acc, key) => {
    acc[key] = data.translations[key];
    return acc;
  }, {});

  const output = {
    translations: sortedTranslations,
    pluralForm: data.pluralForm || 'nplurals=2; plural=(n != 1);',
  };

  fs.writeFileSync(filePath, JSON.stringify(output, null, 2) + '\n', 'utf-8');
  console.log(`  Saved: ${filePath}`);
}

/**
 * Generate Nextcloud .js file from .json translation data
 */
function generateJsFile(locale, data) {
  const filePath = path.join(CONFIG.l10nDir, `${locale}.js`);

  const sortedKeys = Object.keys(data.translations).sort();
  const lines = sortedKeys.map((key, i) => {
    const value = data.translations[key];
    const escapedKey = key.replace(/\\/g, '\\\\').replace(/"/g, '\\"');
    const escapedValue = value.replace(/\\/g, '\\\\').replace(/"/g, '\\"');
    const comma = i < sortedKeys.length - 1 ? ',' : '';
    return `  "${escapedKey}": "${escapedValue}"${comma}`;
  });

  const js = `OC.L10N.register('formvox', {\n${lines.join('\n')}\n}, '${data.pluralForm || 'nplurals=2; plural=(n != 1);'}');\n`;

  fs.writeFileSync(filePath, js, 'utf-8');
  console.log(`  Saved: ${filePath}`);
}

/**
 * Check if a key should be ignored (code artifact, not a real translation)
 */
function shouldIgnore(key) {
  return CONFIG.ignorePatterns.some(pattern => pattern.test(key));
}

/**
 * Main extraction function
 */
function extract() {
  console.log('=== FormVox L10n String Extraction ===\n');

  // 1. Extract from JS/Vue files
  const jsFiles = getAllFiles(CONFIG.srcDirs, CONFIG.extensions);
  console.log(`Scanning ${jsFiles.length} JS/Vue files...`);

  const allKeys = new Map();
  for (const file of jsFiles) {
    const keys = extractKeysFromJsFile(file);
    if (keys.size > 0) {
      console.log(`  ${file}: ${keys.size} strings`);
      keys.forEach((value, key) => allKeys.set(key, value));
    }
  }

  // 2. Extract from PHP files
  const phpFiles = getAllFiles(CONFIG.phpDirs, CONFIG.phpExtensions);
  console.log(`\nScanning ${phpFiles.length} PHP files...`);

  for (const file of phpFiles) {
    const keys = extractKeysFromPhpFile(file);
    if (keys.size > 0) {
      console.log(`  ${file}: ${keys.size} strings`);
      keys.forEach((value, key) => allKeys.set(key, value));
    }
  }

  console.log(`\nTotal unique strings extracted: ${allKeys.size}`);

  // 3. Filter out ignored patterns
  let ignored = 0;
  for (const key of allKeys.keys()) {
    if (shouldIgnore(key)) {
      allKeys.delete(key);
      ignored++;
    }
  }
  if (ignored > 0) {
    console.log(`Filtered out ${ignored} code artifacts`);
  }
  console.log(`Strings to process: ${allKeys.size}\n`);

  // 4. Update source locale (en.json)
  console.log('Updating source locale (en)...');
  const sourceData = loadL10nFile(CONFIG.sourceLocale);
  let newKeysCount = 0;

  for (const [key, value] of allKeys) {
    if (!(key in sourceData.translations)) {
      sourceData.translations[key] = value;
      newKeysCount++;
    }
  }

  if (newKeysCount > 0) {
    console.log(`  Added ${newKeysCount} new strings to en.json`);
  } else {
    console.log('  No new strings found');
  }

  saveL10nFile(CONFIG.sourceLocale, sourceData);
  generateJsFile(CONFIG.sourceLocale, sourceData);

  // 5. Update target locales
  console.log('\nUpdating target locales...');
  for (const locale of CONFIG.targetLocales) {
    console.log(`\n  Processing ${locale}...`);
    const localeData = loadL10nFile(locale);
    let missingCount = 0;
    let removedCount = 0;

    // Add missing keys
    for (const key of Object.keys(sourceData.translations)) {
      if (!(key in localeData.translations)) {
        localeData.translations[key] = `${CONFIG.needsTranslationPrefix}${sourceData.translations[key]}`;
        missingCount++;
      }
    }

    // Remove keys no longer in source
    for (const key of Object.keys(localeData.translations)) {
      if (!(key in sourceData.translations)) {
        delete localeData.translations[key];
        removedCount++;
      }
    }

    if (missingCount > 0) console.log(`    ${missingCount} strings need translation`);
    if (removedCount > 0) console.log(`    Removed ${removedCount} obsolete strings`);

    // Ensure pluralForm matches source
    localeData.pluralForm = sourceData.pluralForm;

    saveL10nFile(locale, localeData);
    generateJsFile(locale, localeData);
  }

  // 6. Summary
  const summary = {
    totalStrings: Object.keys(sourceData.translations).length,
    newStrings: newKeysCount,
    sourceLocale: CONFIG.sourceLocale,
    targetLocales: CONFIG.targetLocales,
    timestamp: new Date().toISOString(),
  };

  const summaryPath = path.join(CONFIG.l10nDir, '.extraction-summary.json');
  fs.writeFileSync(summaryPath, JSON.stringify(summary, null, 2) + '\n');

  console.log('\n=== Summary ===');
  console.log(`Total strings: ${summary.totalStrings}`);
  console.log(`New strings added: ${summary.newStrings}`);
  console.log(`Summary saved to: ${summaryPath}`);

  // 7. Report untranslated strings per locale
  console.log('\n=== Translation Status ===');
  for (const locale of CONFIG.targetLocales) {
    const data = loadL10nFile(locale);
    const total = Object.keys(data.translations).length;
    const needsTranslation = Object.values(data.translations).filter(
      v => v.startsWith(CONFIG.needsTranslationPrefix)
    ).length;
    const untranslated = Object.entries(data.translations).filter(
      ([k, v]) => v === k && !v.startsWith(CONFIG.needsTranslationPrefix)
    ).length;
    const translated = total - needsTranslation - untranslated;
    console.log(`  ${locale}: ${translated}/${total} translated (${needsTranslation} new, ${untranslated} still in English)`);
  }

  return summary;
}

if (require.main === module) {
  extract();
}

module.exports = { extract, CONFIG };
