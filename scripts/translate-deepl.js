#!/usr/bin/env node
/**
 * DeepL Translation Script for Nextcloud L10n (FormVox)
 * Automatically translates missing strings using DeepL API
 *
 * Usage:
 *   DEEPL_API_KEY=your-key node translate-deepl.js
 */

const fs = require('fs');
const path = require('path');
const https = require('https');

const CONFIG = {
  l10nDir: process.env.L10N_DIR || 'l10n',
  sourceLocale: process.env.SOURCE_LOCALE || 'en',
  needsTranslationPrefix: '__NEEDS_TRANSLATION__',
  languageMapping: {
    'nl': 'NL',
    'de': 'DE',
    'fr': 'FR',
    'es': 'ES',
    'it': 'IT',
    'pt': 'PT-PT',
    'pl': 'PL',
    'ru': 'RU',
    'ja': 'JA',
    'zh': 'ZH',
  },
  deeplApiUrl: process.env.DEEPL_API_URL || 'https://api-free.deepl.com',
  batchSize: 50,
  rateLimit: 100,
};

function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

function deeplRequest(texts, targetLang) {
  return new Promise((resolve, reject) => {
    const apiKey = process.env.DEEPL_API_KEY;
    if (!apiKey) {
      reject(new Error('DEEPL_API_KEY environment variable is required'));
      return;
    }

    const postData = new URLSearchParams();
    texts.forEach(text => postData.append('text', text));
    postData.append('target_lang', targetLang);
    postData.append('source_lang', 'EN');
    postData.append('preserve_formatting', '1');

    const postDataString = postData.toString();
    const url = new URL('/v2/translate', CONFIG.deeplApiUrl);

    const options = {
      hostname: url.hostname,
      port: 443,
      path: url.pathname,
      method: 'POST',
      headers: {
        'Authorization': `DeepL-Auth-Key ${apiKey}`,
        'Content-Type': 'application/x-www-form-urlencoded',
        'Content-Length': Buffer.byteLength(postDataString),
      },
    };

    const req = https.request(options, (res) => {
      let data = '';
      res.on('data', chunk => data += chunk);
      res.on('end', () => {
        if (res.statusCode === 200) {
          try {
            const result = JSON.parse(data);
            resolve(result.translations.map(t => t.text));
          } catch (e) {
            reject(new Error(`Failed to parse DeepL response: ${e.message}`));
          }
        } else if (res.statusCode === 403) {
          reject(new Error('DeepL API key is invalid'));
        } else if (res.statusCode === 456) {
          reject(new Error('DeepL quota exceeded'));
        } else {
          reject(new Error(`DeepL API error: ${res.statusCode} - ${data}`));
        }
      });
    });

    req.on('error', e => reject(new Error(`Request failed: ${e.message}`)));
    req.write(postDataString);
    req.end();
  });
}

function loadL10nFile(locale) {
  const filePath = path.join(CONFIG.l10nDir, `${locale}.json`);
  if (!fs.existsSync(filePath)) return null;

  try {
    return JSON.parse(fs.readFileSync(filePath, 'utf-8'));
  } catch (e) {
    console.error(`Error parsing ${filePath}: ${e.message}`);
    return null;
  }
}

function saveL10nFile(locale, data) {
  const filePath = path.join(CONFIG.l10nDir, `${locale}.json`);

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

function getTargetLocales() {
  const files = fs.readdirSync(CONFIG.l10nDir);
  return files
    .filter(f => f.endsWith('.json') && !f.startsWith('.'))
    .map(f => f.replace('.json', ''))
    .filter(l => l !== CONFIG.sourceLocale);
}

function findNeedsTranslation(translations) {
  const needs = {};
  for (const [key, value] of Object.entries(translations)) {
    if (typeof value === 'string' && value.startsWith(CONFIG.needsTranslationPrefix)) {
      needs[key] = value.substring(CONFIG.needsTranslationPrefix.length);
    }
  }
  return needs;
}

async function translateBatch(texts, targetLang) {
  const results = [];
  for (let i = 0; i < texts.length; i += CONFIG.batchSize) {
    const batch = texts.slice(i, i + CONFIG.batchSize);
    console.log(`  Translating batch ${Math.floor(i / CONFIG.batchSize) + 1}/${Math.ceil(texts.length / CONFIG.batchSize)}...`);
    const translated = await deeplRequest(batch, targetLang);
    results.push(...translated);
    if (i + CONFIG.batchSize < texts.length) await sleep(CONFIG.rateLimit);
  }
  return results;
}

async function translate() {
  console.log('Starting DeepL translation for Nextcloud l10n...\n');

  if (!process.env.DEEPL_API_KEY) {
    console.error('Error: DEEPL_API_KEY environment variable is required');
    console.error('Get your API key at: https://www.deepl.com/pro-api');
    process.exit(1);
  }

  const targetLocales = getTargetLocales();
  console.log(`Target locales: ${targetLocales.join(', ')}\n`);

  if (targetLocales.length === 0) {
    console.log('No target locale files found.');
    return;
  }

  const stats = { totalTranslated: 0, byLocale: {} };

  for (const locale of targetLocales) {
    console.log(`\nProcessing locale: ${locale}`);

    const deeplLang = CONFIG.languageMapping[locale.toLowerCase()];
    if (!deeplLang) {
      console.warn(`  No DeepL mapping for '${locale}', skipping`);
      continue;
    }

    const localeData = loadL10nFile(locale);
    if (!localeData) continue;

    const needsTranslation = findNeedsTranslation(localeData.translations);
    const keys = Object.keys(needsTranslation);

    if (keys.length === 0) {
      console.log(`  No strings need translation`);
      continue;
    }

    console.log(`  Found ${keys.length} strings to translate`);

    try {
      const sourceTexts = keys.map(k => needsTranslation[k]);
      const translations = await translateBatch(sourceTexts, deeplLang);

      keys.forEach((key, index) => {
        localeData.translations[key] = translations[index];
      });

      saveL10nFile(locale, localeData);
      stats.totalTranslated += keys.length;
      stats.byLocale[locale] = keys.length;
      console.log(`  Translated ${keys.length} strings`);

    } catch (error) {
      console.error(`  Error translating to ${locale}: ${error.message}`);
    }
  }

  console.log('\n=== Translation Summary ===');
  console.log(`Total strings translated: ${stats.totalTranslated}`);
  for (const [locale, count] of Object.entries(stats.byLocale)) {
    console.log(`  ${locale}: ${count} strings`);
  }

  const statsPath = path.join(CONFIG.l10nDir, '.translation-stats.json');
  fs.writeFileSync(statsPath, JSON.stringify({ ...stats, timestamp: new Date().toISOString() }, null, 2) + '\n');
}

if (require.main === module) {
  translate().catch(error => {
    console.error('Translation failed:', error);
    process.exit(1);
  });
}

module.exports = { translate, CONFIG };
