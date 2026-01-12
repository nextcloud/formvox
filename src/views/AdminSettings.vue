<template>
  <PageBuilder :initial-branding="branding" />
</template>

<script>
import PageBuilder from '../components/pagebuilder/PageBuilder.vue';

export default {
  name: 'AdminSettings',
  components: {
    PageBuilder,
  },
  props: {
    initialBranding: {
      type: Object,
      required: true,
    },
  },
  setup(props) {
    // Pass through the branding data
    const branding = props.initialBranding;

    return {
      branding,
    };
  },
};
</script>
