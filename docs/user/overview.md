# FormVox Overview

## What is FormVox?

FormVox is a file-based forms and polls application for Nextcloud. Unlike traditional form applications that store data in database tables, FormVox stores everything in `.fvform` files - making your forms portable, versionable, and encryption-compatible.

## Key Features

### File-Based Storage
- Each form is a single `.fvform` file containing both the form definition and all responses
- Works with Nextcloud's file versioning
- Compatible with end-to-end encryption
- No database migrations needed

### Rich Question Types
FormVox supports 12+ question types:
- Text and multi-line text
- Single and multiple choice
- Dropdown select
- Date, time, and datetime pickers
- Number input
- Linear scale and star ratings
- Matrix questions
- Email validation

### Advanced Features
- **Conditional Logic** - Show/hide questions based on previous answers
- **Quiz Mode** - Create assessments with automatic scoring
- **Answer Piping** - Reference previous answers in later questions
- **Multi-page Forms** - Organize long forms into pages

### Sharing Options
- Share with Nextcloud users and groups
- Create public links with optional:
  - Password protection
  - Expiration dates
  - User/group restrictions

### Data Export
Export responses to:
- CSV (spreadsheet compatible)
- JSON (for developers)
- Excel (.xlsx)

## Why Choose FormVox?

### vs. Nextcloud Forms
| Feature | FormVox | Nextcloud Forms |
|---------|---------|-----------------|
| Storage | File-based (.fvform) | Database |
| E2E Encryption | Compatible | Not compatible |
| Versioning | Native (file-based) | Limited |
| Portability | Copy/move files | Database export |
| Question Types | 12+ types | Basic types |
| Conditional Logic | Yes | Limited |
| Quiz Mode | Yes | No |
| Answer Piping | Yes | No |

### Perfect For
- **Privacy-focused organizations** - File-based storage with encryption support
- **Surveys and feedback** - Rich question types and analytics
- **Quizzes and assessments** - Built-in scoring and feedback
- **Event registrations** - Templates and conditional logic
- **Data collection** - Export to multiple formats

## Getting Started

Ready to create your first form? Check out our [Getting Started Guide](../getting-started.md).
