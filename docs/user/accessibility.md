# Accessibility

FormVox includes built-in accessibility features to make forms usable for everyone, including users with visual impairments or motor disabilities.

## Text-to-Speech (TTS)

Each question on a public form has a speaker icon next to the question label. When clicked, the browser reads the question aloud using the Web Speech API.

### What gets read

- The question text
- The question description (if present)
- Answer options for choice, multiple choice, and dropdown questions
- Scale range and labels for linear scale questions
- Star count for rating questions
- Row and column labels for matrix questions

### How to use

1. Click the speaker icon next to any question
2. The browser reads the question and its options aloud
3. Click the icon again to stop reading
4. The language is automatically detected from your Nextcloud language setting

> **Note:** TTS requires a modern browser with Web Speech API support (Chrome, Firefox, Safari, Edge). The speaker icon only appears when TTS is supported.

## Screen Reader Support

All question types include proper ARIA attributes for screen readers like VoiceOver (macOS/iOS), NVDA (Windows), or TalkBack (Android):

- Questions are announced with their label and required status
- Validation errors are automatically announced when they appear
- Page changes and submission status are announced
- Matrix questions use proper table semantics

### Supported Screen Readers

| Screen Reader | Platform | Supported |
|---------------|----------|-----------|
| VoiceOver     | macOS / iOS | Yes |
| NVDA          | Windows     | Yes |
| JAWS          | Windows     | Yes |
| TalkBack      | Android     | Yes |

## Keyboard Navigation

Forms can be fully navigated using only the keyboard:

| Key | Action |
|-----|--------|
| **Tab** | Move between questions and form controls |
| **Arrow keys** | Navigate between scale values and star rating options |
| **Home / End** | Jump to first or last option in scale/rating |
| **Enter / Space** | Activate buttons and the file upload zone |

### Skip Link

Press **Tab** at the top of the form to reveal a "Skip to form questions" link. Press **Enter** to bypass header content and jump directly to the questions.

## Focus Management

FormVox automatically manages focus to help keyboard and screen reader users:

- **Validation errors** - Focus moves to the first question with an error, and the error message is announced by screen readers
- **Page navigation** - Focus moves to the first question on the new page
- **Form submission** - Focus moves to the thank-you message so screen readers announce it

## ARIA Attributes

The following ARIA attributes are used throughout the form response interface:

| Attribute | Purpose |
|-----------|---------|
| `role="group"` | Groups each question with its label and input |
| `role="radiogroup"` | Identifies single choice, scale, and rating as radio groups |
| `role="alert"` | Announces validation errors immediately |
| `aria-required` | Indicates required questions |
| `aria-invalid` | Indicates fields with validation errors |
| `aria-describedby` | Links inputs to their description and error messages |
| `aria-live="polite"` | Announces page changes and submission status |
| `aria-live="assertive"` | Announces form errors immediately |
| `aria-label` | Provides labels for icon buttons (speaker, file remove) |

## Browser Compatibility

| Feature | Chrome | Firefox | Safari | Edge |
|---------|--------|---------|--------|------|
| ARIA / Screen Reader | Yes | Yes | Yes | Yes |
| Keyboard Navigation | Yes | Yes | Yes | Yes |
| Text-to-Speech | Yes | Yes | Yes | Yes |

## Next Steps

- Learn about [Advanced Features](advanced-features.md)
- Learn about [Sharing and Publishing](sharing-publishing.md)
- View and analyze [Results](results-analysis.md)
