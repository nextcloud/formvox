import { OdtDocument, fillTemplate } from 'odf-kit';
import { zipSync } from 'fflate';
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';

/**
 * Format an answer value for ODT display.
 */
function formatAnswerForOdt(answer, question) {
	if (answer === undefined || answer === null || answer === '') {
		return '-';
	}

	if (Array.isArray(answer)) {
		// Multiple file uploads
		if (answer.length > 0 && answer[0]?.filename) {
			return answer.map(f => f.originalName || f.filename).join(', ');
		}
		// Table (dynamic rows)
		if (question && question.type === 'table' && answer.length > 0 && typeof answer[0] === 'object') {
			return answer.map((row, i) => {
				const cells = (question.columns || []).map(col =>
					`${col.label}: ${row[col.id] || '-'}`
				);
				return `Row ${i + 1}: ${cells.join(', ')}`;
			}).join('\n');
		}
		if (question && question.options) {
			return answer.map(val => {
				const opt = question.options.find(o => o.value === val);
				return opt ? opt.label : val;
			}).join(', ');
		}
		return answer.join(', ');
	}

	if (typeof answer === 'object') {
		// File upload
		if (answer.filename) {
			return answer.originalName || answer.filename;
		}
		// Matrix type
		if (question && question.type === 'matrix' && question.rows && question.columns) {
			return Object.entries(answer).map(([rowId, colValue]) => {
				const row = question.rows.find(r => r.id === rowId);
				const col = question.columns.find(c => c.value === colValue);
				return `${row?.label || rowId}: ${col?.label || colValue}`;
			}).join(', ');
		}
		return JSON.stringify(answer);
	}

	// Single choice — show label instead of value
	if (question && question.options) {
		const opt = question.options.find(o => o.value === answer);
		if (opt) return opt.label;
	}

	return String(answer);
}

/**
 * Check if a filename has an image extension.
 */
function isImageFile(filename) {
	const ext = (filename || '').split('.').pop().toLowerCase();
	return ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'].includes(ext);
}

/**
 * Get MIME type from filename extension.
 */
function getMimeType(filename) {
	const ext = (filename || '').split('.').pop().toLowerCase();
	const types = { png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', gif: 'image/gif', webp: 'image/webp', bmp: 'image/bmp' };
	return types[ext] || 'image/png';
}

/**
 * Generate an ODT document for a single form response.
 * @param {Object} form - The form definition
 * @param {Object} response - The response data
 * @param {number} [fileId] - Optional file ID for fetching uploaded images
 */
export async function generateResponseOdt(form, response, fileId) {
	const doc = new OdtDocument();

	doc.setMetadata({
		title: form.title,
		creator: 'FormVox',
	});

	// Form title
	doc.addHeading(form.title, 1);

	// Submission info
	const submittedAt = new Date(response.submitted_at).toLocaleString();
	const respondent = response.respondent.type === 'user'
		? response.respondent.display_name
		: 'Anonymous';

	doc.addParagraph((p) => {
		p.addText('Submitted: ', { bold: true });
		p.addText(submittedAt);
	});
	doc.addParagraph((p) => {
		p.addText('Respondent: ', { bold: true });
		p.addText(respondent);
	});

	// Blank line
	doc.addParagraph('');

	// Questions and answers
	for (const question of form.questions) {
		const answer = response.answers[question.id];
		const displayValue = formatAnswerForOdt(answer, question);

		doc.addParagraph(question.question, { spaceBefore: '0.3cm' });
		doc.addParagraph((p) => {
			p.addText(displayValue, { color: '#444444' });
		});

		// Embed uploaded images if fileId is available
		if (fileId && question.type === 'file' && answer) {
			const files = Array.isArray(answer) ? (answer[0]?.filename ? answer : []) : (answer?.filename ? [answer] : []);
			for (const file of files) {
				if (isImageFile(file.filename || file.originalName)) {
					try {
						const url = generateUrl('/apps/formvox/api/form/{fileId}/uploads/{responseId}/{filename}', {
							fileId,
							responseId: file.responseId,
							filename: file.filename,
						});
						const imgResponse = await axios.get(url, { responseType: 'arraybuffer' });
						const imgBytes = new Uint8Array(imgResponse.data);
						doc.addImage(imgBytes, {
							width: '12cm',
							height: '8cm',
							mimeType: getMimeType(file.filename),
						});
					} catch (e) {
						// Image fetch failed, skip embedding
					}
				}
			}
		}
	}

	return doc.save();
}

/**
 * Build a data object for template placeholder substitution.
 * Maps question IDs and Q1/Q2 aliases to answer values.
 */
function buildTemplateData(form, response) {
	const data = {
		response_id: response.id,
		submitted_at: new Date(response.submitted_at).toLocaleString(),
		respondent_name: response.respondent.type === 'user'
			? response.respondent.display_name
			: 'Anonymous',
		respondent_type: response.respondent.type,
		form_title: form.title,
	};

	(form.questions || []).forEach((question, index) => {
		const answer = response.answers[question.id];
		const displayValue = formatAnswerForOdt(answer, question);
		data[question.id] = displayValue;
		data[`Q${index + 1}`] = displayValue;
	});

	return data;
}

/**
 * Generate an ODT from a template file and a single response.
 * Uses odf-kit's fillTemplate with {placeholder} syntax.
 */
export function generateFromTemplate(templateBytes, form, response) {
	const data = buildTemplateData(form, response);
	return fillTemplate(new Uint8Array(templateBytes), data);
}

/**
 * Generate ODT files from a template for all responses, bundled in a ZIP.
 */
export async function generateAllFromTemplateZip(templateBytes, form, responses) {
	const files = {};
	const safeName = (form.title || 'form').replace(/[^a-zA-Z0-9_-]/g, '_');
	const templateUint8 = new Uint8Array(templateBytes);

	for (const response of responses) {
		const bytes = fillTemplate(templateUint8, buildTemplateData(form, response));
		const date = new Date(response.submitted_at).toISOString().split('T')[0];
		const filename = `${safeName}_${date}_${response.id.substring(0, 8)}.odt`;
		files[filename] = bytes;
	}

	return zipSync(files);
}

/**
 * Generate ODT files for all responses and bundle them in a ZIP.
 */
export async function generateAllResponsesZip(form, responses, fileId) {
	const files = {};
	const safeName = (form.title || 'form').replace(/[^a-zA-Z0-9_-]/g, '_');

	for (const response of responses) {
		const bytes = await generateResponseOdt(form, response, fileId);
		const date = new Date(response.submitted_at).toISOString().split('T')[0];
		const filename = `${safeName}_${date}_${response.id.substring(0, 8)}.odt`;
		files[filename] = bytes;
	}

	return zipSync(files);
}

/**
 * Trigger browser download of a Uint8Array as a file.
 */
export function downloadFile(bytes, filename, mimeType = 'application/vnd.oasis.opendocument.text') {
	const blob = new Blob([bytes], { type: mimeType });
	const url = URL.createObjectURL(blob);
	const a = document.createElement('a');
	a.href = url;
	a.download = filename;
	a.click();
	URL.revokeObjectURL(url);
}
