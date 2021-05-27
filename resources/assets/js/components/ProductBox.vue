<template>
  <div class="item col-sm-4 col-lg-4">
    <div class="thumbnail">
      <a :href="product.link">
        <img :alt="product.name" class="group list-group-image" :src="product.cover" />
        <h4 class="group inner list-group-item-heading product-title">{{ product.name }}</h4>
      </a>
      <div class="stampede-review-area">
        <span class="stamped-product-reviews-badge stamped-main-badge" :data-id="product.review_id"></span>
      </div>
      <div class="caption" v-if="product.setup=='variable'">
        <div class="row more-detail category--v">
          <div class="col-xs-8">
            <p class="lead price">{{ product.currency.symbol + product.price }}</p>

            <span v-if="getAttribute('Volume')">|</span>

            <p class="lead oz">{{ getAttribute('Volume') }}</p>
          </div>
          <div class="col-xs-4">
            <a class="pull-right btn btn-primary btn-cart-add" :href="product.link">SELECT</a>
          </div>
        </div>
      </div>
      <div v-else class="caption">
        <div class="row more-detail category--v" v-show="!itemCount">
          <div class="col-xs-8">
            <p class="lead price">{{ product.currency.symbol + product.price }}</p>
            <span v-if="getAttribute('Volume')">|</span>
            <p class="lead oz">{{ getAttribute('Volume') }}</p>
          </div>
          <div class="col-xs-4">
            <button
              class="pull-right btn btn-primary btn-cart-add"
              @click="global.addToCart(product)"
            >ADD</button>
          </div>
        </div>
        <div class="row more-detail category--v basket-count" v-show="itemCount">
          <div class="col-xs-4">
            <button
              class="btn btn-secondary cart-minus"
              data-action="minus"
              @click="global.decrement(product.id)"
            >
              <span class="ti-minus"></span>
            </button>
          </div>
          <div class="col-xs-4">
            <div class="counter-box">
              <input type="hidden" class="form-control" value="1" min="0" max="10" />
              <!--add min and max attribute for validation-->
              <span class="count">{{ itemCount }}</span>
              <p>in basket</p>
            </div>
          </div>
          <div class="col-xs-4">
            <button
              class="btn btn-secondary cart-plus"
              data-action="plus"
              @click="global.increment(product.id)"
            >
              <span class="ti-plus"></span>
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import { Global } from "./Global.js";

var Cart = [];

export default {
  name: "productbox",
  props: {
    product: {
      type: Array / Object
    }
  },
  computed: {
    itemCount() {
      return this.global.productItemCount(this.product.id);
    }
  },
  methods: {
    getAttribute(key) {
      if (!this.product.extra_attributes) return;

      let attribute = _.find(this.product.extra_attributes, { label: key });

      return attribute ? attribute.value : null;
    }
  },
  data() {
    return {
      global: Global
    };
  },
  mounted() {}
};
</script>
