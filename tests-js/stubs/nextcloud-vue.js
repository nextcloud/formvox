/**
 * Lightweight stubs for the @nextcloud/vue components FormVox uses.
 *
 * The real library imports CSS and expects a running Nextcloud, which a unit
 * test neither has nor needs. Each stub is a minimal Vue component that renders
 * its slot and re-exposes modelValue + the update event, so a component's own
 * logic (what it binds down, what it emits up) is observable.
 *
 * NcTextField deliberately does NOT reproduce the real component's
 * null->toString() crash (#134); tests assert what OUR wrapper passes it (never
 * null), which is the actual contract we care about.
 */
import { defineComponent, h } from 'vue'

function passthroughInput(name) {
	return defineComponent({
		name,
		props: {
			modelValue: { type: null, default: undefined },
		},
		emits: ['update:model-value'],
		setup(props, { emit, attrs }) {
			return () => h('input', {
				...attrs,
				'data-stub': name,
				value: props.modelValue,
				onInput: (e) => emit('update:model-value', e.target.value),
			})
		},
	})
}

function passthroughContainer(name) {
	return defineComponent({
		name,
		setup(_props, { slots }) {
			return () => h('div', { 'data-stub': name }, slots.default ? slots.default() : [])
		},
	})
}

export const NcTextField = passthroughInput('NcTextField')
export const NcInputField = passthroughInput('NcInputField')
export const NcTextArea = passthroughInput('NcTextArea')
export const NcSelect = passthroughInput('NcSelect')
export const NcCheckboxRadioSwitch = passthroughInput('NcCheckboxRadioSwitch')

export const NcButton = passthroughContainer('NcButton')
export const NcModal = passthroughContainer('NcModal')
export const NcDialog = passthroughContainer('NcDialog')
export const NcActions = passthroughContainer('NcActions')
export const NcActionButton = passthroughContainer('NcActionButton')
export const NcLoadingIcon = passthroughContainer('NcLoadingIcon')
export const NcEmptyContent = passthroughContainer('NcEmptyContent')
export const NcNoteCard = passthroughContainer('NcNoteCard')
