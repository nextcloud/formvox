# FormVox User Guide

A complete guide to creating and managing forms, polls, and quizzes in FormVox.

## Table of Contents

1. [Getting Started](#getting-started)
2. [Question Types](#question-types)
3. [Question Settings](#question-settings)
4. [Form Settings](#form-settings)
5. [Sharing and Publishing](#sharing-and-publishing)
6. [Conditional Logic (Branching)](#conditional-logic-branching)
7. [Quiz Mode](#quiz-mode)
8. [Multi-page Forms](#multi-page-forms)
9. [Viewing Results](#viewing-results)
10. [Exporting Data](#exporting-data)

---

## Getting Started

### Creating a New Form

1. Open the FormVox app from the Nextcloud menu
2. Click **"New Form"**
3. Choose a template:
   - **Blank Form** - Start from scratch
   - **Poll** - Simple voting with one question
   - **Survey** - Multiple questions with different types
   - **Registration** - Contact information collection
   - **Demo Form** - Example showcasing all features

### The Form Editor

The editor consists of three parts:
- **Left sidebar** - Form settings and options
- **Center area** - Questions and preview
- **Top bar** - Save, share, and view responses

---

## Question Types

### Text
Single-line text input for short answers like names, email addresses, or short responses.

### Textarea
Multi-line text field for longer responses such as comments, descriptions, or explanations.

### Choice (Single Select)
Radio buttons where users can select exactly one option from a list.

**Settings:**
- Options (add/remove/reorder via drag & drop)
- Quiz mode with scores per option

### Multiple (Multi Select)
Checkboxes where users can select multiple options from a list.

**Settings:**
- Options (add/remove/reorder via drag & drop)
- Quiz mode with scores per option

### Dropdown
Single selection from a dropdown menu. Ideal for long lists of options.

**Settings:**
- Options (add/remove/reorder via drag & drop)
- Quiz mode with scores per option

### Date
Date picker for selecting a date without time.

### DateTime
Combined date and time picker for selecting both a date and time.

### Time
Time picker for selecting only a time without a date.

### Number
Numeric input for quantities, ages, amounts, etc.

### Scale (Linear Scale)
Number scale for ratings or opinions (e.g., 1-5 or 1-10).

**Settings:**
- Minimum value (default: 1)
- Maximum value (default: 5)
- Start label (e.g., "Disagree")
- End label (e.g., "Agree")

### Rating (Stars)
Star rating for quick feedback.

**Settings:**
- Maximum stars (default: 5)

### Matrix (Grid)
Table format with questions in rows and answer options in columns. Useful for comparing multiple items using the same scale.

**Settings:**
- Rows (sub-questions)
- Columns (answer options)

### File Upload
Allow respondents to upload files with their response.

---

## Question Settings

Every question has the following settings:

### Required
When enabled, users must answer the question before submitting. Required questions are marked with an asterisk (*).

### Description
Additional text below the question to provide clarification or instructions.

### Conditional Display
Show the question only when certain conditions are met based on previous answers. See [Conditional Logic](#conditional-logic-branching).

### Question ID
A unique identifier used for piping (referencing answers in other questions) and conditional logic. Automatically generated but can be customized.

---

## Form Settings

### General Settings

| Setting | Description |
|---------|-------------|
| **Title** | The form title displayed to respondents |
| **Description** | Introduction text shown at the top of the form |
| **Submit Button Text** | Custom text for the submit button (default: "Submit") |
| **Confirmation Message** | Message shown after successful submission |

### Submission Settings

| Setting | Description |
|---------|-------------|
| **Allow Multiple Submissions** | When disabled, users can only submit once (based on fingerprint or user account) |
| **Require Login** | Users must be logged into Nextcloud to respond |
| **Expiration Date** | Date and time when the form stops accepting responses |

### Results Settings

| Setting | Description |
|---------|-------------|
| **Show Results** | Never / After submission / Always visible |
| **Anonymize Results** | Hide respondent identities in results |

---

## Sharing and Publishing

### Private Sharing (Nextcloud Users)
Share the form with specific Nextcloud users or groups. They can access the form through their Nextcloud account.

### Public Link
Generate a public link that anyone can use, even without a Nextcloud account.

1. Click **"Share"** in the top bar
2. Enable **"Public link"**
3. Copy the generated URL

**Public Link Settings:**
- Password protection
- Expiration date
- Require login (users must have Nextcloud account)

### Embedding
Use the public link in an iframe to embed the form on external websites:

```html
<iframe src="YOUR_PUBLIC_LINK" width="100%" height="600"></iframe>
```

---

## Conditional Logic (Branching)

Show or hide questions based on previous answers.

### Setting Up Conditional Logic

1. Click the gear icon on a question
2. Go to **"Show if"** settings
3. Create a condition:
   - Select the controlling question
   - Choose an operator (equals, not equals, contains, etc.)
   - Enter the comparison value

### Available Operators

| Operator | Description |
|----------|-------------|
| **equals** | Exact match |
| **not equals** | Does not match |
| **contains** | Text contains value |
| **not contains** | Text does not contain value |
| **is empty** | No answer given |
| **is not empty** | Any answer given |
| **greater than** | Numeric comparison |
| **less than** | Numeric comparison |
| **in** | Value is one of multiple options |
| **not in** | Value is not one of the options |

### Combining Conditions

Use **AND** and **OR** to combine multiple conditions:
- **AND** - All conditions must be true
- **OR** - At least one condition must be true

### Example

Show a follow-up question only when the user selects "Other":

1. Question 1: "How did you hear about us?" (Choice)
   - Options: Social Media, Friend, Advertisement, Other
2. Question 2: "Please specify:" (Text)
   - Show if: Question 1 equals "Other"

---

## Quiz Mode

Create quizzes with scoring by assigning points to answer options.

### Enabling Quiz Mode

1. Create a form with Choice or Multiple questions
2. Click on an answer option
3. Enter a **Score** value (e.g., 10 points for correct answer, 0 for incorrect)

### Score Display

After submission, users see:
- Total score
- Maximum possible score
- Percentage
- Score per question (optional)

### Tips for Quizzes

- Use 0 points for wrong answers, positive points for correct answers
- For partial credit, assign different points to each option
- Combine with conditional logic to create adaptive quizzes

---

## Multi-page Forms

Split long forms into multiple pages for better user experience.

### Creating Pages

1. Click **"Add Page"** in the form editor
2. Drag questions between pages
3. Set page titles and descriptions

### Page Navigation

Users see:
- Progress indicator (page 1 of 3)
- Previous/Next buttons
- Option to review answers before submission

### Page Settings

| Setting | Description |
|---------|-------------|
| **Page Title** | Heading for each page |
| **Page Description** | Introduction text for the page |
| **Allow Going Back** | Let users return to previous pages |

---

## Viewing Results

### Results Dashboard

Access results by clicking **"Responses"** in the form editor.

**Summary View:**
- Total number of responses
- Response statistics per question
- Charts and graphs for visual analysis

**Individual Responses:**
- View each submission separately
- Delete individual responses
- Filter by date or answer

### Statistics Per Question Type

| Type | Statistics Shown |
|------|------------------|
| Choice/Multiple/Dropdown | Bar chart with counts per option |
| Text/Textarea | List of text responses |
| Number/Scale/Rating | Average, minimum, maximum |
| Date/DateTime/Time | Timeline or list |
| Matrix | Table with counts per cell |

---

## Exporting Data

Export responses for analysis in external tools.

### CSV Export
Spreadsheet format compatible with Excel, Google Sheets, LibreOffice.

1. Go to Responses
2. Click **"Export"**
3. Select **"CSV"**

### JSON Export
Structured data format for developers and data analysis.

1. Go to Responses
2. Click **"Export"**
3. Select **"JSON"**

### Excel Export
Native Excel format (.xlsx) with formatting.

1. Go to Responses
2. Click **"Export"**
3. Select **"Excel"**

### Export Contents

All exports include:
- Response ID
- Submission timestamp
- Respondent type (anonymous/user)
- All question answers
- Quiz scores (if applicable)

---

## Tips and Best Practices

### Form Design
- Keep forms concise - only ask what you need
- Use clear, simple language
- Group related questions together
- Use conditional logic to keep forms relevant

### Question Writing
- Be specific and unambiguous
- Avoid leading questions
- Provide all necessary answer options

### Testing
- Always test your form before sharing
- Try different answer combinations
- Check conditional logic flows
- Verify on mobile devices

### Privacy
- Enable login requirement for sensitive data
- Use password protection for confidential forms
- Consider expiration dates for time-sensitive forms
- Anonymize results when appropriate

---

## Keyboard Shortcuts

| Shortcut | Action |
|----------|--------|
| `Ctrl + S` | Save form |
| `Ctrl + Z` | Undo |
| `Ctrl + Y` | Redo |
| `Delete` | Remove selected question |
| `Ctrl + D` | Duplicate question |

---

## Troubleshooting

### Form won't save
- Check your internet connection
- Ensure you have write permissions to the folder
- Try refreshing the page

### Public link not working
- Verify the link is enabled in share settings
- Check if the form has expired
- Ensure password is correct (if protected)

### Duplicate submission error
- The form may have "Allow multiple submissions" disabled
- Clear browser cookies to submit again (if testing)

### Questions not showing
- Check conditional logic settings
- Verify the controlling question has been answered

---

## Need Help?

- Report issues: [GitHub Issues](https://github.com/anthropics/claude-code/issues)
- Check the README for technical details
- Contact your Nextcloud administrator
