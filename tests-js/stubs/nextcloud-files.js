/**
 * Stub for @nextcloud/files (only the bits FormVox touches).
 */
export const formatFileSize = (bytes) => `${bytes} B`
export const getFileExtension = (name) => {
	const i = String(name).lastIndexOf('.')
	return i === -1 ? '' : String(name).slice(i + 1)
}
