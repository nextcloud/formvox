# Exporting Data

FormVox allows you to export your form responses in multiple formats for external analysis and archiving.

## Export Formats

### CSV (Comma-Separated Values)

Best for:
- Spreadsheet applications (Excel, Google Sheets, LibreOffice Calc)
- Simple data analysis
- Importing into other systems

Structure:
- One row per response
- One column per question
- First row contains question titles

### JSON (JavaScript Object Notation)

Best for:
- Developers and programmers
- API integrations
- Preserving data structure

Structure:
```json
{
  "form": {
    "title": "Customer Survey",
    "questions": [...]
  },
  "responses": [
    {
      "submitted": "2024-01-15T10:30:00Z",
      "answers": {
        "q1": "John Doe",
        "q2": "Very satisfied"
      }
    }
  ]
}
```

### Excel (.xlsx)

Best for:
- Microsoft Excel users
- Advanced analysis with formulas
- Sharing with non-technical users

Features:
- Formatted columns
- Multiple sheets (summary + raw data)
- Charts (optional)

### ZIP (File Uploads)

For forms with file upload questions, download all uploaded files:

1. Open the **Results** view
2. Click **Download uploads** or the ZIP icon
3. All uploaded files are downloaded as a ZIP archive

The ZIP file structure:
```
uploads/
├── response_1/
│   ├── document.pdf
│   └── photo.jpg
├── response_2/
│   └── attachment.docx
```

**Note:** This option only appears for forms that have file upload questions with submitted files.

## How to Export

### From the Results View

1. Open your form
2. Click **Results** in the toolbar
3. Click the **Export** button
4. Choose your format (CSV, JSON, or Excel)
5. Configure options (see below)
6. Click **Download**

### Export Options

**Include:**
- [ ] Response timestamps
- [ ] Response IDs
- [ ] Partial responses (incomplete submissions)

**Format:**
- [ ] Include question numbers
- [ ] Use question IDs as headers (for JSON)
- [ ] Flatten matrix questions

**Date Range:**
- All responses
- Last 7 days
- Last 30 days
- Custom range

## Working with Exported Data

### In Excel/Spreadsheets

After exporting to CSV or Excel:

1. Open the file in your spreadsheet application
2. Use filters to analyze subsets
3. Create pivot tables for summaries
4. Build charts for visualization

**Tip:** For CSV files, use "Data > Text to Columns" if columns don't separate correctly.

### In Programming Languages

Using the JSON export:

**Python:**
```python
import json

with open('responses.json') as f:
    data = json.load(f)

for response in data['responses']:
    print(response['answers'])
```

**JavaScript:**
```javascript
const data = require('./responses.json');

data.responses.forEach(response => {
    console.log(response.answers);
});
```

## Automated Exports

### Scheduled Exports

Currently, FormVox doesn't support scheduled exports. For regular exports:

1. Set a calendar reminder
2. Export manually at regular intervals
3. Consider using the API for automation

### API Export

For developers, use the FormVox API to export programmatically:

```bash
curl -H "Authorization: Bearer TOKEN" \
  https://your-nextcloud.com/apps/formvox/api/forms/FORM_ID/responses
```

See [API Reference](../architecture/api-reference.md) for details.

## Data Privacy

### Before Exporting

Consider:
- Who will have access to the exported file?
- Does it contain personal information?
- Are you complying with data protection regulations (GDPR, etc.)?

### Sensitive Data

For forms with sensitive data:
- Export only what you need
- Store exports securely
- Delete exports when no longer needed
- Anonymize data if possible

## Backup and Archiving

### Regular Backups

For important forms:
1. Export data regularly (weekly/monthly)
2. Store exports in a secure location
3. Keep multiple versions

### Archiving Old Data

To archive and clear old responses:
1. Export all responses
2. Verify the export is complete
3. Delete responses from FormVox
4. Store the export for records

### Form File Backup

Remember: The `.fvform` file itself contains all data:
- Form structure
- All responses

Backing up the file backs up everything.

## Troubleshooting

### Large Exports

For forms with many responses:
- Use date range filters to export in batches
- Choose CSV (smaller file size)
- Allow extra time for download

### Encoding Issues

If special characters appear incorrectly:
- Ensure UTF-8 encoding when opening CSV
- Use Excel's "Import" feature instead of double-clicking
- Try the Excel (.xlsx) format instead

### Missing Data

If responses are missing:
- Check date range filters
- Verify "include partial responses" if needed
- Check form permissions

## Next Steps

- Review [Results Analysis](results-analysis.md) features
- Learn about [API access](../architecture/api-reference.md)
- Configure [Security settings](../admin/security.md)
