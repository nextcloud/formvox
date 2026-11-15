import formIcon from '../../img/filetypes/application-x-fvform.svg?raw'
import appIcon from '../../img/app-dark.svg?raw'

/** The form file icon as Files shows it; the + New menu keeps its colours. */
export const FORM_ICON_SVG = formIcon

/** The same shape in one colour, for the actions menu next to Nextcloud's own monochrome icons. */
export const FORM_ICON_MONO_SVG = appIcon.replace('fill="#000"', 'fill="currentColor"')
