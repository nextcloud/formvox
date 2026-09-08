import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import ArrowLeftIcon from '@/components/icons/ArrowLeftIcon.vue'
import BranchIcon from '@/components/icons/BranchIcon.vue'
import ChartIcon from '@/components/icons/ChartIcon.vue'
import ChatIcon from '@/components/icons/ChatIcon.vue'
import CheckIcon from '@/components/icons/CheckIcon.vue'
import ChevronIcon from '@/components/icons/ChevronIcon.vue'
import CloseIcon from '@/components/icons/CloseIcon.vue'
import CogIcon from '@/components/icons/CogIcon.vue'
import CopyIcon from '@/components/icons/CopyIcon.vue'
import DeleteIcon from '@/components/icons/DeleteIcon.vue'
import DemoIcon from '@/components/icons/DemoIcon.vue'
import DownloadIcon from '@/components/icons/DownloadIcon.vue'
import DragIcon from '@/components/icons/DragIcon.vue'
import EditIcon from '@/components/icons/EditIcon.vue'
import EyeIcon from '@/components/icons/EyeIcon.vue'
import FacebookIcon from '@/components/icons/FacebookIcon.vue'
import FileIcon from '@/components/icons/FileIcon.vue'
import FolderIcon from '@/components/icons/FolderIcon.vue'
import FormIcon from '@/components/icons/FormIcon.vue'
import GlobeIcon from '@/components/icons/GlobeIcon.vue'
import GroupIcon from '@/components/icons/GroupIcon.vue'
import ImageIcon from '@/components/icons/ImageIcon.vue'
import ImportIcon from '@/components/icons/ImportIcon.vue'
import InfoIcon from '@/components/icons/InfoIcon.vue'
import InstagramIcon from '@/components/icons/InstagramIcon.vue'
import LinkedInIcon from '@/components/icons/LinkedInIcon.vue'
import PagesIcon from '@/components/icons/PagesIcon.vue'
import PaletteIcon from '@/components/icons/PaletteIcon.vue'
import PlusIcon from '@/components/icons/PlusIcon.vue'
import PollIcon from '@/components/icons/PollIcon.vue'
import RegistrationIcon from '@/components/icons/RegistrationIcon.vue'
import ShareIcon from '@/components/icons/ShareIcon.vue'
import SpeakerIcon from '@/components/icons/SpeakerIcon.vue'
import StarIcon from '@/components/icons/StarIcon.vue'
import SurveyIcon from '@/components/icons/SurveyIcon.vue'
import TextIcon from '@/components/icons/TextIcon.vue'
import TwitterIcon from '@/components/icons/TwitterIcon.vue'
import UserIcon from '@/components/icons/UserIcon.vue'
import UsersIcon from '@/components/icons/UsersIcon.vue'
import YouTubeIcon from '@/components/icons/YouTubeIcon.vue'

/**
 * Smoke tests for the presentational SVG icon components.
 *
 * Each icon renders a single root <svg> and accepts a numeric `size` prop
 * bound to the svg's width/height. These are trivial presentational
 * components, so we just assert they mount and render an <svg>, plus that
 * the size prop flows through.
 */
const icons = {
	ArrowLeftIcon,
	BranchIcon,
	ChartIcon,
	ChatIcon,
	CheckIcon,
	ChevronIcon,
	CloseIcon,
	CogIcon,
	CopyIcon,
	DeleteIcon,
	DemoIcon,
	DownloadIcon,
	DragIcon,
	EditIcon,
	EyeIcon,
	FacebookIcon,
	FileIcon,
	FolderIcon,
	FormIcon,
	GlobeIcon,
	GroupIcon,
	ImageIcon,
	ImportIcon,
	InfoIcon,
	InstagramIcon,
	LinkedInIcon,
	PagesIcon,
	PaletteIcon,
	PlusIcon,
	PollIcon,
	RegistrationIcon,
	ShareIcon,
	SpeakerIcon,
	StarIcon,
	SurveyIcon,
	TextIcon,
	TwitterIcon,
	UserIcon,
	UsersIcon,
	YouTubeIcon,
}

describe('icon components', () => {
	for (const [name, Component] of Object.entries(icons)) {
		describe(name, () => {
			it('mounts and renders an <svg> root element', () => {
				const wrapper = mount(Component)
				const svg = wrapper.find('svg')
				expect(svg.exists()).toBe(true)
				expect(wrapper.element.tagName.toLowerCase()).toBe('svg')
			})

			it('applies the size prop to the svg width/height', () => {
				const wrapper = mount(Component, { props: { size: 32 } })
				const svg = wrapper.get('svg')
				expect(svg.attributes('width')).toBe('32')
				expect(svg.attributes('height')).toBe('32')
			})
		})
	}
})
