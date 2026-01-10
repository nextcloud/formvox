<template>
  <svg :width="size" :height="size" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <polyline points="6 9 12 15 18 9" />
  </svg>
</template>
<script>
export default { name: 'ChevronIcon', props: { size: { type: Number, default: 24 } } };
</script>
