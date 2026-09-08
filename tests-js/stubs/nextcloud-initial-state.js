/**
 * Stub for @nextcloud/initial-state. Returns undefined by default; a test can
 * override by mocking this module per-spec if a component reads a specific key
 * on mount.
 */
export const loadState = (app, key, fallback = undefined) => fallback
