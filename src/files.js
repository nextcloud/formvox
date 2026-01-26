/**
 * FormVox - Files app integration for Nextcloud 28+
 * Opens .fvform files in FormVox and adds "New form" menu entry
 */

import { registerFileAction, FileAction, addNewFileMenuEntry, NewMenuEntryCategory, DefaultType } from '@nextcloud/files';
import { generateUrl } from '@nextcloud/router';
import axios from '@nextcloud/axios';

// FormVox icon as inline SVG
const formvoxIconSvg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">
  <rect x="15" y="10" width="70" height="80" rx="5" fill="#fff" stroke="#0082c9" stroke-width="3"/>
  <rect x="25" y="25" width="35" height="4" rx="2" fill="#0082c9"/>
  <rect x="25" y="35" width="50" height="4" rx="2" fill="#ccc"/>
  <circle cx="28" cy="50" r="4" fill="#0082c9"/>
  <rect x="38" y="48" width="30" height="4" rx="2" fill="#666"/>
  <circle cx="28" cy="62" r="4" stroke="#0082c9" stroke-width="2" fill="none"/>
  <rect x="38" y="60" width="25" height="4" rx="2" fill="#666"/>
  <circle cx="28" cy="74" r="4" stroke="#0082c9" stroke-width="2" fill="none"/>
  <rect x="38" y="72" width="35" height="4" rx="2" fill="#666"/>
</svg>`;

/**
 * Register file action to open .fvform files
 */
function registerFvformFileAction() {
    try {
        const openAction = new FileAction({
            id: 'formvox-open',
            displayName: () => 'Edit with FormVox',
            iconSvgInline: () => formvoxIconSvg,
            enabled: (nodes) => {
                if (nodes.length !== 1) return false;
                const node = nodes[0];
                return node.type === 'file' &&
                       (node.mime === 'application/x-fvform' ||
                        node.basename?.toLowerCase().endsWith('.fvform'));
            },
            exec: async (node) => {
                const fileId = node.fileid;
                if (fileId) {
                    const appUrl = generateUrl('/apps/formvox/edit/{fileId}', { fileId });
                    window.location.href = appUrl;
                    return true;
                }
                return false;
            },
            default: DefaultType.DEFAULT,
            order: -50,
        });

        registerFileAction(openAction);
    } catch (e) {
        console.error('FormVox: Failed to register file action', e);
    }
}

/**
 * Register "New form" in the new file menu
 */
function registerNewFormVoxMenu() {
    try {
        addNewFileMenuEntry({
            id: 'formvox-new',
            displayName: 'New form',
            iconSvgInline: formvoxIconSvg,
            category: NewMenuEntryCategory.CreateNew,
            order: 30,
            handler: async (context, content) => {
                await createNewFvformFile(context.path || '/');
            },
        });
    } catch (e) {
        console.error('FormVox: Failed to register new file menu entry', e);
        tryLegacyNewFileMenu();
    }
}

/**
 * Try the legacy NewFileMenu API for older NC versions
 */
function tryLegacyNewFileMenu() {
    const checkAndRegister = () => {
        if (window.OCA?.Files?.NewFileMenu) {
            window.OCA.Files.NewFileMenu.addMenuEntry({
                id: 'formvox-new',
                displayName: 'New form',
                templateName: 'New form.fvform',
                iconClass: 'icon-formvox',
                iconSvgInline: formvoxIconSvg,
                fileType: 'file',
                handler: (context) => createNewFvformFile(context?.dir || '/'),
            });
        }
    };

    let attempts = 0;
    const interval = setInterval(() => {
        if (window.OCA?.Files?.NewFileMenu || attempts++ > 20) {
            clearInterval(interval);
            checkAndRegister();
        }
    }, 300);
}

/**
 * Create a new FormVox file via API
 */
async function createNewFvformFile(directory) {
    try {
        // Use the FormVox API to create a new form
        const response = await axios.post(
            generateUrl('/apps/formvox/api/forms'),
            {
                title: 'New form',
                path: directory.replace(/^\//, ''), // Remove leading slash
            }
        );

        const { fileId } = response.data;

        if (fileId) {
            // Open the file in FormVox editor
            const appUrl = generateUrl('/apps/formvox/edit/{fileId}', { fileId });
            window.location.href = appUrl;
        } else {
            // Fallback: reload page
            window.dispatchEvent(new CustomEvent('files:reload'));
            setTimeout(() => window.location.reload(), 100);
        }
    } catch (error) {
        console.error('FormVox: Error creating form:', error);
        if (window.OC?.Notification) {
            window.OC.Notification.showTemporary('Could not create FormVox form: ' + (error.message || 'Unknown error'));
        } else {
            alert('Could not create FormVox form: ' + (error.message || 'Unknown error'));
        }
    }
}

/**
 * Setup click handler for .fvform files as fallback
 */
function setupFvformClickHandler() {
    document.addEventListener('click', (e) => {
        const nameElement = e.target.closest('.files-list__row-name-link, .files-list__row-name, a.name, .innernametext');
        if (!nameElement) return;

        const fileRow = e.target.closest('[data-cy-files-list-row], tr[data-file], .files-list__row');
        if (!fileRow) return;

        const filename = fileRow.getAttribute('data-cy-files-list-row-name') ||
                        fileRow.getAttribute('data-file') ||
                        fileRow.querySelector('.files-list__row-name-text')?.textContent;

        if (!filename?.toLowerCase().endsWith('.fvform')) return;

        const fileId = fileRow.getAttribute('data-cy-files-list-row-fileid') ||
                      fileRow.getAttribute('data-id');

        if (fileId) {
            e.preventDefault();
            e.stopPropagation();
            const appUrl = generateUrl('/apps/formvox/edit/{fileId}', { fileId });
            window.location.href = appUrl;
        }
    }, true);
}

// Initialize
function init() {
    registerFvformFileAction();
    registerNewFormVoxMenu();
    setupFvformClickHandler();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => setTimeout(init, 100));
} else {
    setTimeout(init, 100);
}
