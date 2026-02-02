#!/usr/bin/env node
/**
 * Nextcloud L10n String Extractor for FormVox
 * Extracts translatable strings from Vue components and JS files
 *
 * Supports Nextcloud's t() and n() translation functions
 */

const fs = require('fs');
const path = require('path');

// Configuration
const CONFIG = {
  srcDirs: ['src', 'js'],
  extensions: ['.vue', '.js', '.ts'],
  l10nDir: 'l10n',
  sourceLocale: 'en',
  targetLocales: ['nl', 'de', 'fr'],
  // Nextcloud translation patterns
  patterns: [
    // Nextcloud format: t('appname', 'text') - capture the second argument
    /\bt\(\s*['"]formvox['"]\s*,\s*['"]([^'"]+)['"]/g,
    // Also match t('text') for simple cases
    /\bt\(\s*['"]([^'"]+)['"]\s*\)/g,
    // $t('appname', 'text') in Vue templates
    /\$t\(\s*['"]formvox['"]\s*,\s*['"]([^'"]+)['"]/g,
    // n('appname', 'singular', 'plural', count)
    /n\(\s*['"]formvox['"]\s*,\s*['"]([^'"]+)['"],\s*['"]([^'"]+)['"]/g,
  ],
  needsTranslationPrefix: '__NEEDS_TRANSLATION__',
};

/**
 * Extract translation keys from a file
 */
function extractKeysFromFile(filePath) {
  const content = fs.readFileSync(filePath, 'utf-8');
  const keys = new Map(); // key -> source text

  for (const pattern of CONFIG.patterns) {
    pattern.lastIndex = 0;
    let match;
    while ((match = pattern.exec(content)) !== null) {
      // For t() calls, key and text are the same
      keys.set(match[1], match[1]);
      // For n() calls, also capture plural form
      if (match[2]) {
        keys.set(match[2], match[2]);
      }
    }
  }

  return keys;
}

/**
 * Get all source files
 */
function getAllFiles(dirs, extensions) {
  const files = [];

  function walkDir(dir) {
    if (!fs.existsSync(dir)) return;

    const entries = fs.readdirSync(dir, { withFileTypes: true });
    for (const entry of entries) {
      const fullPath = path.join(dir, entry.name);
      if (entry.isDirectory()) {
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
 * Save Nextcloud l10n JSON file
 */
function saveL10nFile(locale, data) {
  const filePath = path.join(CONFIG.l10nDir, `${locale}.json`);

  // Sort translations alphabetically
  const sortedTranslations = Object.keys(data.translations).sort().reduce((acc, key) => {
    acc[key] = data.translations[key];
    return acc;
  }, {});

  const output = {
    translations: sortedTranslations,
    pluralForm: data.pluralForm || 'nplurals=2; plural=(n != 1);'
  };

  fs.writeFileSync(filePath, JSON.stringify(output, null, 2) + '\n', 'utf-8');
  console.log(`Saved: ${filePath}`);
}

/**
 * Main extraction function
 */
function extract() {
  console.log('Extracting l10n strings from source files...\n');

  const files = getAllFiles(CONFIG.srcDirs, CONFIG.extensions);
  console.log(`Found ${files.length} files to scan\n`);

  // Extract all keys
  const allKeys = new Map();

  for (const file of files) {
    const keys = extractKeysFromFile(file);
    if (keys.size > 0) {
      console.log(`  ${file}: ${keys.size} strings`);
      keys.forEach((value, key) => allKeys.set(key, value));
    }
  }

  console.log(`\nTotal unique strings found: ${allKeys.size}\n`);

  // Load and update source locale
  const sourceData = loadL10nFile(CONFIG.sourceLocale);
  let newKeysCount = 0;

  for (const [key, value] of allKeys) {
    if (!(key in sourceData.translations)) {
      sourceData.translations[key] = value;
      newKeysCount++;
    }
  }

  if (newKeysCount > 0) {
    console.log(`Added ${newKeysCount} new strings to ${CONFIG.sourceLocale}.json`);
  }

  saveL10nFile(CONFIG.sourceLocale, sourceData);

  // Update target locales - mark missing translations
  for (const locale of CONFIG.targetLocales) {
    const localeData = loadL10nFile(locale);
    let missingCount = 0;

    for (const key of Object.keys(sourceData.translations)) {
      if (!(key in localeData.translations)) {
        localeData.translations[key] = `${CONFIG.needsTranslationPrefix}${sourceData.translations[key]}`;
        missingCount++;
      }
    }

    if (missingCount > 0) {
      console.log(`${locale}: ${missingCount} strings need translation`);
    }

    saveL10nFile(locale, localeData);
  }

  // Output summary
  const summary = {
    totalStrings: allKeys.size,
    newStrings: newKeysCount,
    sourceLocale: CONFIG.sourceLocale,
    targetLocales: CONFIG.targetLocales,
    timestamp: new Date().toISOString()
  };

  const summaryPath = path.join(CONFIG.l10nDir, '.extraction-summary.json');
  fs.writeFileSync(summaryPath, JSON.stringify(summary, null, 2) + '\n');
  console.log(`\nSummary saved to: ${summaryPath}`);

  return summary;
}

if (require.main === module) {
  extract();
}

module.exports = { extract, CONFIG };
