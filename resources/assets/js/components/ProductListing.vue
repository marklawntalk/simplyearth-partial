<template>
  <div>
    <div class="free-oil-selector-funnel" v-if="type == 'freeoilfunnel'">
      <div class="row list-group product-list" style="margin-top:40px;">
        <free-oil-funnel-box
          @selected="hasSelected(product.id)"
          :selected="product.id == selected_product"
          v-for="(product, index) in products"
          :product="product"
          :key="product.id"
        ></free-oil-funnel-box>
      </div>

      <div class="text-center special-offer-buttons">
        <button
          @click="addFreeOil"
          class="btn a-btn-claim btn-lg"
          :disabled="!selected_product"
        >
          <i v-if="adding_free_oil" class="fa fa-spin fa-spinner"></i> Claim
          Your Free Oil
        </button>
      </div>
    </div>
    <div class="featured-product-list" v-if="type == 'featuredproducts'">
      <featured-products
          @selected="hasSelected(product.id)"
          :selected="product.id == selected_product"
          v-for="(product, index) in featured_products"
          :product="product"
          :key="product.id"
          :addButtonText="'Add To Cart'"
        ></featured-products>
    </div>
    <div class="free-oil-selector-funnel" v-else-if="type == 'special-offer'">
      <div class="row a-mt-2">
        <div class="col-lg-3">
          <form
            @submit.prevent="searchProducts"
            method="GET"
            action="/special-offer"
            class="product-filter-inner"
          >
            <div class="search-form">
              <div class="input-group">
                <input
                  type
                  class="form-control"
                  v-model="search_q"
                  placeholder="Search for oils"
                />
                <span class="input-group-addon" id="basic-addon2">
                  <button
                    type="submit"
                    class="btn btn-xs btn-link"
                    style="padding:0;"
                  >
                    <i class="fa fa-search"></i>
                  </button>
                </span>
              </div>
            </div>
          </form>
        </div>
        <div class="a-free-ship-progress col-lg-4 col-lg-offset-1">
          <div class="a-free-shipping-bar" v-if="freeShippingAway > 0">
            <div>
              You are ${{ freeShippingAway }} away from
              <strong>FREE SHIPPING</strong>
            </div>
            <div
              class="a-progress-bar"
              :style="'width:' + (specialOfferSubTotal / 29.0) * 100 + '%'"
            >
              <span>${{ specialOfferSubTotal }}</span>
            </div>
          </div>

          <div class="a-free-shipping-bar" v-else>
            <div>Congrats! 🎉 You've earned <strong>FREE SHIPPING</strong></div>
            <div
              class="a-progress-bar a-progress-bar-green"
              :style="'width: 100%'"
            >
              <span>100%</span>
            </div>
          </div>
        </div>
      </div>
      <div class="row list-group product-list product-list-special-offer">
        <free-oil-funnel-box
          @selected="hasSelectedSpecialOffer(product)"
          :selected="
            special_offer_products.filter(item => item.id == product.id)
              .length > 0
          "
          v-for="(product, index) in products"
          :product="product"
          :key="product.id"
          add-button-text="10% OFF"
        ></free-oil-funnel-box>
      </div>

      <div class="text-center special-offer-buttons">
        <button
          type="button"
          @click="addSpecialOrders"
          class="btn btn-lg btn-primary"
          :disabled="special_offer_products.length == 0"
        >
          <i v-if="adding_free_oil" class="fa fa-spin fa-spinner"></i> Add To My
          Order
        </button>
        <a href="/checkout" class="btn btn-white btn-lg"
          >Continue to Checkout</a
        >
      </div>
    </div>
    <div v-else>
      <div class="row list-group product-list">
        <productbox
          v-for="product in products"
          :product="product"
          :key="product.id"
        ></productbox>
      </div>
    </div>

    <center v-if="show_load_more && collection.next_page_url !== null">
      <button class="btn btn-plain btn-load-more" @click="loadMore">
        LOAD MORE PRODUCTS
      </button>
    </center>
  </div>
</template>

<script>
import numeral from "numeral"
import { Global } from "./Global.js"
import Productbox from "./ProductBox.vue"
import FreeOilFunnelBox from "./FreeOilFunnelBox.vue"
import DiscountedProductBox from "./DiscountedProductBox.vue"
import FeaturedProducts from "./FeaturedProducts"
export default {
  name: "productlisting",
  components: {
    Productbox,
    FreeOilFunnelBox,
    DiscountedProductBox,
    FeaturedProducts
  },
  props: ["collection", "show_load_more", "type"],
  computed: {
    specialOfferSubTotal() {
      let subtotal = 0

      this.special_offer_products.forEach(product => {
        subtotal += parseFloat(product.price)
      })

      return numeral(subtotal).format("0,0,.00")
    },
    freeShippingAway() {
      return numeral(Math.max(29 - this.specialOfferSubTotal, 0)).format(
        "0,0,.00",
      )
    },
  },
  methods: {
    hasSelected(id) {
      this.selected_product = id
    },
    hasSelectedSpecialOffer(product) {
      if (
        this.special_offer_products.filter(item => item.id === product.id)
          .length > 0
      ) {
        this.special_offer_products = this.special_offer_products.filter(
          item => item.id !== product.id,
        )
        return
      }

      return this.special_offer_products.push(product)
    },
    addFreeOil() {
      this.adding_free_oil = true
      this.global.addFreeOil(this.selected_product, response => {
        window.location.href = "/special-offer"
      })
    },
    addSpecialOrders() {
      this.adding_free_oil = true
      this.global.addBulkProducts(
        this.special_offer_products.map(item => item.id),
        response => {
          window.location.href = "/checkout"
        },
      )
    },
    searchProducts() {
      axios.get("/special-offer?q=" + this.search_q).then(response => {
        this.updatePagination(response.data)
        this.products = response.data.data

        if (typeof StampedFn == "object") {
          this.$nextTick(() => {
            setTimeout(() => {
              StampedFn.loadBadges()
            }, 50)
          })
        }
      })
    },
    loadMore() {
      axios.get(this.collection.next_page_url).then(response => {
        this.updatePagination(response.data)
        this.products = _.unionBy(this.products, response.data.data, "id")

        if (typeof StampedFn == "object") {
          this.$nextTick(() => {
            setTimeout(() => {
              StampedFn.loadBadges()
            }, 50)
          })
        }
      })
    },
    updatePagination(pagination) {
      this.collection.current_page = pagination.current_page
      this.collection.first_page_url = pagination.first_page_url
      this.collection.from = pagination.from
      this.collection.last_page = pagination.last_page
      this.collection.last_page_url = pagination.last_page_url
      this.collection.next_page_url = pagination.next_page_url
      this.collection.path = pagination.path
      this.collection.per_page = pagination.per_page
      this.collection.prev_page_url = pagination.prev_page_url
      this.collection.to = pagination.to
      this.collection.total = pagination.total
    },
  },
  data() {
    return {
      search_q: null,
      selected_product: null,
      global: Global,
      products: this.collection ? this.collection.data : null,
      adding_free_oil: false,
      special_offer_products: [],
      featured_products: this.collection
    }
  },
}
</script>
