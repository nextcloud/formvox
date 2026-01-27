# Advanced Features

FormVox includes powerful features for creating dynamic, intelligent forms.

## Conditional Logic

Conditional logic allows you to show or hide questions based on previous answers. This creates a personalized experience for respondents.

![Conditional Logic](../../screenshots/Question%20-%20Conditional.png)

### How It Works

1. Select a question you want to conditionally show
2. Click **Add condition** in the question settings
3. Configure the condition:
   - **If** - Select the question to check
   - **Operator** - equals, not equals, contains, etc.
   - **Value** - The answer value to match
4. The question will only appear when the condition is met

### Multiple Conditions

You can add multiple conditions with AND/OR logic:

- **AND** - All conditions must be true
- **OR** - Any condition can be true

### Example Use Cases

**Customer Feedback Form:**
- Q1: "How satisfied are you?" (1-5 scale)
- Q2: "What could we improve?" (only shows if Q1 ≤ 3)

**Event Registration:**
- Q1: "Will you attend the dinner?" (Yes/No)
- Q2: "Dietary restrictions?" (only shows if Q1 = Yes)

## Quiz Mode

Transform your form into a quiz with automatic scoring.

![Quiz Mode](../../screenshots/Question%20-%20Quiz.png)

### Enabling Quiz Mode

1. Open form settings
2. Enable **Quiz mode**
3. For each question, mark the correct answer(s)
4. Assign point values

### Scoring Options

- **Points per question** - Assign different point values to questions
- **Partial credit** - For multiple choice, award partial points
- **Pass/fail threshold** - Set a minimum score to pass

### Results Display

After submission, respondents can see:
- Their total score
- Correct/incorrect answers
- Feedback for each question (optional)

### Quiz Question Types

Best question types for quizzes:
- Single choice (one correct answer)
- Multiple choice (multiple correct answers)
- Dropdown (one correct answer)
- Short text (exact match)

## Answer Piping

Use answers from previous questions in later questions or messages.

### Syntax

Use double curly braces with the question ID:
```
{{question_id}}
```

### Example

**Q1:** "What is your name?" → User answers "John"

**Q2:** "Hi {{q1}}, what department do you work in?"

Displays as: "Hi John, what department do you work in?"

### Where to Use Piping

- Question text
- Question descriptions
- Page titles
- Confirmation messages

## Multi-Page Forms

Organize long forms into multiple pages for better user experience.

### Creating Pages

1. Click **Add page** in the question list
2. Give the page a title (optional)
3. Drag questions into the page

### Page Navigation

Respondents see:
- **Next** button to go to the next page
- **Previous** button to go back
- Progress indicator (optional)

### Page Logic

Combine pages with conditional logic:
- Skip entire pages based on answers
- Show different paths for different users

## Form Branding

Customize the appearance of your forms to match your organization.

### Branding Options

- **Header image** - Logo or banner at the top
- **Background color** - Form background
- **Accent color** - Buttons and highlights
- **Custom CSS** - Advanced styling (admin only)

### Per-Form Branding

Each form can have its own branding, or inherit from organization defaults.

## Duplicate Prevention

Prevent users from submitting multiple responses.

### Methods

- **Browser fingerprint** - Detect same browser/device
- **Nextcloud user** - One submission per logged-in user
- **Email verification** - Require email confirmation

### Settings

1. Open form settings
2. Under **Submission settings**
3. Enable duplicate prevention
4. Choose the method

## Rate Limiting

Protect your forms from spam and abuse.

### Public Form Protection

For public forms:
- **Submissions per minute** - Limit rapid submissions
- **CAPTCHA** - Add bot protection (requires admin setup)

## Next Steps

- Learn about [Sharing and Publishing](sharing-publishing.md)
- View and analyze [Results](results-analysis.md)
- [Export your data](exporting-data.md)
