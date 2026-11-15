/**
 * FormVox - Files app integration for Nextcloud 28+
 * Opens .fvform files in FormVox and adds "New form" menu entry
 */

import { registerFileAction, addNewFileMenuEntry, NewMenuEntryCategory, DefaultType } from '@nextcloud/files';
import { generateUrl } from '@nextcloud/router';
import axios from '@nextcloud/axios';
import { registerNewFormEntry, registerOpenAction } from './utils/openAction';
import { FORM_ICON_MONO_SVG, FORM_ICON_SVG } from './utils/formIcon';
import { t } from '@/utils/l10n';

/**
 * Register file action to open .fvform files
 */
function registerFvformFileAction() {
    try {
        registerOpenAction({
            registerFileAction,
            version: window.OC?.config?.version,
            open: (node) => {
                const fileId = node?.fileid;
                if (!fileId) {
                    return false;
                }
                window.location.href = generateUrl('/apps/formvox/edit/{fileId}', { fileId });
                return true;
            },
            icon: FORM_ICON_MONO_SVG,
            defaultType: DefaultType.DEFAULT,
            scope: window,
        });
    } catch (e) {
        console.error('FormVox: Failed to register file action', e);
    }
}

/**
 * Register "New form" in the new file menu
 */
function registerNewFormVoxMenu() {
    registerNewFormEntry({
        addNewFileMenuEntry,
        version: window.OC?.config?.version,
        entry: {
            id: 'formvox-new',
            displayName: t('formvox', 'New form'),
            iconSvgInline: FORM_ICON_SVG,
            category: NewMenuEntryCategory.CreateNew,
            order: 30,
            handler: async (context) => {
                await createNewFvformFile(context?.path || '/');
            },
        },
        scope: window,
    }).catch((e) => {
        console.error('FormVox: Failed to register new file menu entry', e);
        tryLegacyNewFileMenu();
    });
}

/**
 * Try the legacy NewFileMenu API for older NC versions
 */
function tryLegacyNewFileMenu() {
    const checkAndRegister = () => {
        if (window.OCA?.Files?.NewFileMenu) {
            window.OCA.Files.NewFileMenu.addMenuEntry({
                id: 'formvox-new',
                displayName: t('formvox', 'New form'),
                templateName: t('formvox', 'New form') + '.fvform',
                iconClass: 'icon-formvox',
                iconSvgInline: FORM_ICON_SVG,
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
                title: t('formvox', 'New form'),
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
        // Shown as text, so the message is not HTML-escaped.
        // TRANSLATORS: {error} is a technical message, often in English
        const message = t('formvox', 'Could not create FormVox form: {error}',
            { error: error.message || t('formvox', 'Unknown error') },
            undefined, { escape: false, sanitize: false });
        if (window.OC?.Notification) {
            window.OC.Notification.showTemporary(message);
        } else {
            alert(message);
        }
    }
}

// As the script runs: Files' init script made the registries already, and NC 32's upload picker copies the New menu
// once, when the first folder shows (review of the 3a fixes: it lacked New form until a folder change).
registerFvformFileAction();
registerNewFormVoxMenu();
