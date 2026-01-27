# FormVox vs Nextcloud Forms

This document compares FormVox with Nextcloud Forms to help you choose the right solution.

## Overview

| Aspect | FormVox | Nextcloud Forms |
|--------|---------|-----------------|
| **Storage** | File-based (.fvform) | Database |
| **Architecture** | Single file per form | Database tables |
| **First Release** | 2024 | 2020 |
| **Status** | Active development | Mature, stable |

## Feature Comparison

### Question Types

| Question Type | FormVox | Nextcloud Forms |
|---------------|---------|-----------------|
| Short text | Yes | Yes |
| Long text | Yes | Yes |
| Email | Yes | No |
| Single choice | Yes | Yes |
| Multiple choice | Yes | Yes |
| Dropdown | Yes | Yes |
| Date | Yes | Yes |
| Time | Yes | No |
| DateTime | Yes | No |
| Number | Yes | No |
| Linear scale | Yes | No |
| Star rating | Yes | No |
| Matrix | Yes | No |

**FormVox advantage:** More question types, especially for surveys and ratings.

### Advanced Features

| Feature | FormVox | Nextcloud Forms |
|---------|---------|-----------------|
| Conditional logic | Yes (AND/OR) | Limited |
| Quiz mode | Yes | No |
| Answer piping | Yes | No |
| Multi-page forms | Yes | No |
| Custom branding | Yes | No |
| File uploads | Yes | Yes |

**FormVox advantage:** More advanced form logic and customization.

### Data & Export

| Feature | FormVox | Nextcloud Forms |
|---------|---------|-----------------|
| CSV export | Yes | Yes |
| JSON export | Yes | No |
| Excel export | Yes | No |
| Charts | Yes | Yes |
| Real-time results | Yes | Yes |

**FormVox advantage:** More export formats.

### Security & Privacy

| Feature | FormVox | Nextcloud Forms |
|---------|---------|-----------------|
| E2E encryption | Compatible | Not compatible |
| Server-side encryption | Yes | Yes |
| Password protection | Yes | Yes |
| Expiration dates | Yes | Yes |
| Rate limiting | Yes | Limited |
| GDPR compliance | Yes | Yes |

**FormVox advantage:** E2E encryption compatibility.

### Integration

| Feature | FormVox | Nextcloud Forms |
|---------|---------|-----------------|
| Files app integration | Yes | No |
| Nextcloud sharing | Yes | Limited |
| REST API | Yes | Yes |
| File versioning | Yes | No |
| Backup (file copy) | Yes | Database backup |

**FormVox advantage:** Native file integration.

## Architecture Differences

### FormVox: File-Based

```
User creates form
       │
       ▼
┌─────────────────┐
│  .fvform file   │  ← Single JSON file
│  - Form def     │
│  - Responses    │
└─────────────────┘
```

**Advantages:**
- Portable (copy/move like any file)
- Works with E2E encryption
- Native file versioning
- Easy backup (just copy files)
- No database migrations

**Disadvantages:**
- Concurrent access needs locking
- Very large forms may be slower
- File size grows with responses

### Nextcloud Forms: Database

```
User creates form
       │
       ▼
┌─────────────────┐
│   Database      │
│   - forms       │  ← Multiple tables
│   - questions   │
│   - responses   │
└─────────────────┘
```

**Advantages:**
- Efficient for very large datasets
- Native database querying
- Better concurrent handling
- Familiar architecture

**Disadvantages:**
- No E2E encryption support
- Requires database migrations
- Harder to backup individual forms
- Less portable

## When to Use FormVox

Choose FormVox when you need:

1. **End-to-end encryption** - FormVox works with Nextcloud's E2E encryption
2. **File-based workflow** - Forms as files fit your organization better
3. **Advanced question types** - Rating scales, matrices, quizzes
4. **Conditional logic** - Complex form branching
5. **Custom branding** - Per-form visual customization
6. **Portability** - Easy to copy, move, share forms as files

## When to Use Nextcloud Forms

Choose Nextcloud Forms when you need:

1. **Stability** - Mature, battle-tested solution
2. **Large scale** - Thousands of responses per form
3. **Simple forms** - Basic surveys without advanced features
4. **Integration** - Part of Nextcloud's core ecosystem
5. **Lower resource usage** - Database may be more efficient for large datasets

## Migration

### From Nextcloud Forms to FormVox

Currently, there's no automated migration tool. To migrate:

1. Export responses from Nextcloud Forms (CSV)
2. Create equivalent form in FormVox
3. Import responses manually or via API

### From FormVox to Nextcloud Forms

1. Export responses from FormVox (CSV/JSON)
2. Recreate form in Nextcloud Forms
3. Note: Some question types may not be available

## Coexistence

FormVox and Nextcloud Forms can run side-by-side:
- Different apps, different storage
- Users can choose per-form
- No conflicts or interference

## Performance Comparison

### Small Forms (<100 responses)

| Metric | FormVox | Nextcloud Forms |
|--------|---------|-----------------|
| Form load | ~100ms | ~100ms |
| Submit response | ~150ms | ~100ms |
| View results | ~200ms | ~150ms |

Both perform similarly for small forms.

### Large Forms (1000+ responses)

| Metric | FormVox | Nextcloud Forms |
|--------|---------|-----------------|
| Form load | ~100ms | ~100ms |
| Submit response | ~200ms | ~100ms |
| View results | ~500ms* | ~300ms |

*FormVox uses pagination; initial load may be slower.

## Conclusion

| Choose | When |
|--------|------|
| **FormVox** | Privacy-focused, advanced features, file-based workflow |
| **Nextcloud Forms** | Simple needs, large scale, established solution |

Both are excellent choices - the right one depends on your specific requirements.
