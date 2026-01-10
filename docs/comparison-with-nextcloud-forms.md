# FormVox vs Nextcloud Forms

This document explains the differences between FormVox and Nextcloud Forms to help you choose the right app for your needs.

## Overview

Both apps let you create forms and surveys in Nextcloud, but they take fundamentally different approaches:

| | FormVox | Nextcloud Forms |
|--|---------|-----------------|
| **Storage** | File-based (.fvform JSON files) | Database tables |
| **Philosophy** | "Forms are files" | "Forms are database records" |
| **Best for** | Portable, encrypted, versioned forms | Large-scale surveys, database integration |

## Quick Decision Guide

### Choose FormVox if you need:

- **End-to-end encryption** - Forms work in E2E encrypted folders
- **File versioning** - Track changes via Nextcloud's version history
- **Portability** - Copy a form file to back it up or share it
- **Offline access** - Sync forms via Nextcloud desktop client
- **No database dependencies** - Zero database tables or migrations
- **Advanced features** - Conditional logic, quiz mode, matrix questions

### Choose Nextcloud Forms if you need:

- **Very large response volumes** - Database handles thousands of responses better
- **Deep Nextcloud integration** - Tight coupling with other database-driven apps
- **Official Nextcloud support** - Maintained by Nextcloud GmbH

## Feature Comparison

### Question Types

| Type | FormVox | Nextcloud Forms |
|------|:-------:|:---------------:|
| Short text | Yes | Yes |
| Long text | Yes | Yes |
| Single choice (radio) | Yes | Yes |
| Multiple choice (checkbox) | Yes | Yes |
| Dropdown | Yes | Yes |
| Date | Yes | Yes |
| Date + Time | Yes | No |
| Time only | Yes | No |
| Number | Yes | Yes |
| Linear scale (1-10) | Yes | No |
| Star rating | Yes | No |
| Matrix/Grid | Yes | No |
| File upload | Yes | Yes |

### Advanced Features

| Feature | FormVox | Nextcloud Forms |
|---------|:-------:|:---------------:|
| **Conditional logic** | Yes (AND/OR) | No (requested) |
| **Quiz mode with scoring** | Yes | No (requested) |
| **Multi-page forms** | Yes | No |
| **Piping (variables)** | Yes | No |
| **Form templates** | Yes (5 templates) | No |
| **Duplicate prevention** | Yes (fingerprint) | No |
| **Password protection** | Yes | Yes |
| **Expiration date** | Yes | Yes |
| **Public sharing** | Yes | Yes |
| **CSV export** | Yes | Yes |
| **JSON export** | Yes | No |
| **Excel export** | Yes | No |

## Architecture Differences

### FormVox: File-Based

```
MyForm.fvform (single JSON file)
├── Form definition (title, description)
├── Questions array
├── Settings (anonymous, expiration, etc.)
├── Permissions (roles, sharing)
├── Performance index
└── All responses
```

**Advantages:**
- Copy file = complete backup
- Works with E2E encryption
- Native Nextcloud file versioning
- No database migrations
- Sync offline via desktop client

**Trade-offs:**
- File size grows with responses
- Best for forms with < 10,000 responses

### Nextcloud Forms: Database-Based

```
Database tables
├── oc_forms_v2_forms
├── oc_forms_v2_questions
├── oc_forms_v2_options
├── oc_forms_v2_submissions
├── oc_forms_v2_answers
├── oc_forms_v2_shares
└── oc_forms_v2_uploaded_files
```

**Advantages:**
- Scales to very large response counts
- SQL queries for complex reporting

**Trade-offs:**
- Requires database setup
- Not E2E encryption compatible
- No native file versioning
- Backup requires database export

## Unique FormVox Features Explained

### 1. Conditional Logic (Branching)

Show or hide questions based on previous answers:

```
Q1: Do you own a car? [Yes / No]
Q2: What brand? (only shown if Q1 = Yes)
Q3: How do you commute? (only shown if Q1 = No)
```

Supports complex conditions with AND/OR operators.

### 2. Quiz Mode

Assign points to answer options and automatically calculate scores:

- Set correct answers with point values
- Show total score after submission
- Display percentage and breakdown by question

### 3. Matrix Questions

Create grid/table questions for rating multiple items:

```
            | Poor | Fair | Good | Excellent |
Service     |  O   |  O   |  O   |     O     |
Quality     |  O   |  O   |  O   |     O     |
Value       |  O   |  O   |  O   |     O     |
```

### 4. Piping (Dynamic Text)

Reference previous answers in question text:

```
Q1: What is your name?
Q2: Nice to meet you, {{Q1}}! How can we help you today?
```

### 5. Form Templates

Quick-start with pre-built templates:
- **Blank** - Start from scratch
- **Poll** - Simple voting
- **Survey** - Feedback collection
- **Registration** - Event sign-ups
- **Demo** - Showcases all features

## Migration Between Apps

### From Nextcloud Forms to FormVox

Export your form responses as CSV from Nextcloud Forms, then create a new form in FormVox and import if needed.

### From FormVox to Nextcloud Forms

Export responses as CSV/JSON from FormVox. Forms need to be recreated manually in Nextcloud Forms (without advanced features like conditional logic).

## Summary

FormVox and Nextcloud Forms are **complementary apps** that serve different needs:

- **FormVox**: The choice for users who want file-based portability, E2E encryption compatibility, and advanced features like conditional logic and quiz mode.

- **Nextcloud Forms**: The choice for users who prefer database-backed storage and official Nextcloud maintenance.

Both apps can be installed side-by-side. Choose based on your specific requirements.
