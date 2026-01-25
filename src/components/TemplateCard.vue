<template>
	<button
		type="button"
		class="template-card"
		:style="{ '--template-color': color }"
		@click="$emit('select')"
	>
		<div class="template-card__header">
			<component :is="icon" :size="32" class="template-card__icon" />
		</div>
		<div class="template-card__body">
			<span class="template-card__name">{{ name }}</span>
			<span class="template-card__description">{{ description }}</span>
		</div>
	</button>
</template>

<script>
export default {
	name: 'TemplateCard',
	props: {
		name: {
			type: String,
			required: true,
		},
		description: {
			type: String,
			required: true,
		},
		icon: {
			type: Object,
			required: true,
		},
		color: {
			type: String,
			default: '#0082c9',
		},
	},
	emits: ['select'],
}
</script>

<style scoped lang="scss">
.template-card {
	display: flex;
	flex-direction: column;
	width: 140px;
	min-width: 140px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 10px);
	background: var(--color-main-background);
	cursor: pointer;
	overflow: hidden;
	transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out, border-color 0.2s ease-in-out;

	&:hover {
		transform: translateY(-2px);
		box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
		border-color: var(--template-color);
	}

	&:focus-visible {
		outline: 2px solid var(--color-primary-element);
		outline-offset: 2px;
	}

	&__header {
		display: flex;
		align-items: center;
		justify-content: center;
		height: 80px;
		background: var(--template-color);
		color: #fff;
	}

	&__icon {
		filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.2));
	}

	&__body {
		display: flex;
		flex-direction: column;
		align-items: center;
		padding: 12px 8px;
		text-align: center;
	}

	&__name {
		font-size: 14px;
		font-weight: 600;
		color: var(--color-main-text);
		margin-bottom: 4px;
	}

	&__description {
		font-size: 11px;
		color: var(--color-text-maxcontrast);
		line-height: 1.3;
	}
}

@media (prefers-reduced-motion: reduce) {
	.template-card {
		transition: none;

		&:hover {
			transform: none;
		}
	}
}
</style>
