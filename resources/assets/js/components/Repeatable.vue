<template>
<div class="repeatable-container">
    <slot name="add-button" :addItem="addItem"></slot>
    <div class="repeatable-list">
        <div class="repeatable-item" v-for="(item,index) in repeatable"><slot name="list" :repeatable="repeatable" :item="item" :removeItem="removeItem" :index="index"></slot></div>
    </div>
</div>
</template>

<script>
export default {
  name: "Repeatable",
  props: ["list", "blank"],
  watch:{
    repeatable: {
      handler(repeatable, old_repeatable) {
        this.$emit('updated', repeatable)
      },
      deep: true
    },
  },
  methods: {
    removeItem(index) {
      this.repeatable.splice(index, 1);
    },

    addItem() {
      let clone = _.cloneDeep(this.blank)
      this.repeatable.push(clone);
    }
  },

  data() {
    return {
      repeatable: Object.values(this.list) || []
    };
  }
};
</script>