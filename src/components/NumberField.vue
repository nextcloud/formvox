<template>
  <NcTextField
    v-bind="$attrs"
    type="number"
    :model-value="displayValue"
    @update:model-value="onUpdate"
  />
</template>

<script>
import { computed } from 'vue'
import { NcTextField } from '@nextcloud/vue'

/**
 * Number input wrapper around NcTextField with a null-safe render.
 *
 * NcTextField (via NcInputField) renders its value with
 * `modelValue.value.toString()` and has no guard for null — and it declares
 * modelValue as required. But `v-model.number` hands back `null` the moment a
 * numeric input is cleared, so binding a nullable number straight to
 * NcTextField crashes the render and the field disappears for good (#134,
 * still unfixed in @nextcloud/vue as of 9.11.0).
 *
 * This wrapper shows an empty string whenever the value is not a finite number,
 * so NcTextField never receives null. On input it emits a parsed number, or
 * `null` when the field is empty — leaving the caller to decide what "empty"
 * means for that field (drop the key, or fall back to a default). Every numeric
 * setting in the editor should use this instead of a raw NcTextField so no
 * future field re-introduces the same crash.
 */
export default {
  name: 'NumberField',
  components: { NcTextField },
  inheritAttrs: false,
  props: {
    modelValue: {
      type: [Number, String, null],
      default: null,
    },
  },
  emits: ['update:model-value'],
  setup(props, { emit }) {
    // Never hand NcTextField a null — that is the crash. Anything that isn't a
    // finite number renders as an empty input.
    const displayValue = computed(() => {
      const v = props.modelValue
      return typeof v === 'number' && Number.isFinite(v) ? String(v) : ''
    })

    const onUpdate = (val) => {
      const num = typeof val === 'number' ? val : parseFloat(val)
      const isEmpty = val === '' || val === null || val === undefined || Number.isNaN(num)
      // null signals "empty" to the caller; a value is always a real number.
      emit('update:model-value', isEmpty ? null : num)
    }

    return { displayValue, onUpdate }
  },
}
</script>
