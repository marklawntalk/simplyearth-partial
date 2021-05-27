require("./bootstrap-admin")
require("./form")

var dt = require("datatables.net")

window.Vue = require("vue")

/**
 * Next, we will create a fresh Vue application instance and attach it to
 * the page. Then, you may begin adding components to this application
 * or customize the JavaScript scaffolding to fit your unique needs.
 */

Vue.component("droppable", require("./components/Droppable.vue"))
Vue.component("medialibrary", require("./components/MediaLibrary.vue"))
Vue.component("repeatable", require("./components/Repeatable.vue"))

Vue.component(
  "productattribute",
  require("./components/products/ProductAttribute.vue"),
)

Vue.component("productrelated", require("./components/products/ProductRelated"))

import swal from "sweetalert"
import VueTagsInput from "@johmun/vue-tags-input"

var ProductRecipe = {
  template: `
  <div>
    <div class="form-group">
      <label class="col-sm-2 control-label">Show Recipe?:</label>
      <div class="col-sm-10">
        <input type="checkbox" v-model="product.show_recipe" name="show_recipe" class="checkbox" value="1" />
      </div>
    </div>
    <div  :class="{'super-invisible':product.show_recipe!=1}">
      <div class="form-group"><label class="col-sm-2 control-label">Recipe Title:</label>
          <div class="col-sm-10">
              <input type="text" name="recipe_title" v-model="product.recipe_title" class="form-control" />
          </div>
      </div>
      <div class="form-group"><label class="col-sm-2 control-label">Recipe:</label>
          <div class="col-sm-10">
              <textarea name="recipe" v-model="product.recipe" class="form-control post-editor"></textarea>
          </div>
      </div>
    </div>
  </div>
  `,
  props: ["init"],
  data() {
    return {
      product: this.init,
    }
  },
}
var ProductSetup = {
  template: `
  <div>

  <div class="form-group">
      <label class="col-sm-2 control-label">Setup:</label>
      <div class="col-sm-2">
      <select name="setup" v-model="setup" class="form-control">
      <option value="single">Single Product</option>
      <option value="variable">Variable Product</option>
    </select>
      </div>
  </div>

  </div>
  `,
  data() {
    return {
      setup: SimplyEarth.product.setup || "single",
    }
  },
}

var ProductPlans = {
  template: `
  <div class="form-group">
    <label class="col-sm-2 control-label">Plans:</label>
    <div class="col-sm-4">
    <input type="text" name="available_plans[]" v-model="plan" placeholder="Deposit|Cycles|Amount  e.g. 20|8|10" class="form-control" />
    </div>
  </div>
  `,

  props: ["available_plans"],
  data() {
    return {
      plan: this.available_plans ? this.available_plans[0] : "",
    }
  },
}

var WholesalePricing = {
  props: ["init"],
  template: `
  <div>
  <div class="form-group">
      <label class="col-sm-2 control-label">Wholesale pricing?:</label>
      <div class="col-sm-10">
          <input type="checkbox" v-model="product.wholesale_pricing" name="wholesale_pricing" class="checkbox" value="1" />
      </div>
  </div>

  <div class="form-group" v-if="product.wholesale_pricing==1">
      <label class="col-sm-2 control-label">Wholesale Price:</label>
      <div class="col-sm-4">
          <div class="input-group">
              <span class="input-group-addon">$</span>
              <input type="text" name="wholesale_price" v-model="product.wholesale_price" class="form-control" />
          </div>
      </div>
  </div>
</div>
  `,
  data() {
    return {
      product: this.init,
    }
  },
}

var ShippingInput = {
  props: ["shipping", "weight"],
  template: `

  <div>

  <div class="form-group">
      <label class="col-sm-2 control-label">Needs shipping?:</label>
      <div class="col-sm-10">
        <input type="checkbox" v-model="shipping" name="shipping" class="checkbox" value="1" />
      </div>
  </div>

  <div class="form-group" v-if="shipping==1">
      <label class="col-sm-2 control-label">Weight:</label>
      <div class="col-sm-4">
        <div class="input-group">
        <input type="text" name="weight" v-model="weight" class="form-control" />
        <span class="input-group-addon">lb</span>
        </div>
      </div>
  </div>

  </div>

  `,
}

var SubscriptionInput = {
  props: ["subscription_type", "subscription_months"],
  template: `

  <div>


  <div class="form-group">
    <label class="col-sm-2 control-label">Type:</label>
    <div class="col-sm-2">
        <select name="type" v-model="type" class="form-control">
          <option value="default">Default</option>
          <option value="subscription">Subscription</option>
          <option value="gift_card">Gift Card</option>
        </select>
    </div>
  </div>

  <div class="form-group" v-if="type === 'subscription'">
    <label class="col-sm-2 control-label">Months:</label>
    <div class="col-sm-2">
        <input type="number" step="1" min="1" name="subscription_months" v-model="subscription_months" class="form-control" />
    </div>
  </div>

  </div>

  `,

  data() {
    return {
      type: this.subscription_type,
    }
  },
}

var TagsInput = {
  template: `
  <div>
  <vue-tags-input
      v-model="tag"
      :tags="tags"
      @tags-changed="tagsChanged"
      :autocomplete-items="autocompleteItems"
      :add-only-from-autocomplete="true"
      />
    <input type="hidden" name="tags[]" :value="item.text" v-for="item in list" />
  </div>
  `,
  components: {
    VueTagsInput,
  },
  props: ["tags"],
  data() {
    return {
      tag: "",
      list: this.tags,
      debounce: null,
      autocompleteItems: [],
    }
  },
  methods: {
    tagsChanged(newTags) {
      this.list = newTags
    },
    initTags() {
      if (this.tag.length === 0) return
      const url = `/admin/customers/tags/search/${this.tag}`

      clearTimeout(this.debounce)
      this.debounce = setTimeout(() => {
        axios
          .get(url)
          .then(response => {
            this.autocompleteItems = _.map(response.data, a => {
              return {
                text: a.name,
              }
            })
          })
          .catch(() => {
            console.warn("Oh. Something went wrong")
          })
      }, 350)
    },
  },
  watch: {
    tag: "initTags",
  },
}
var PageBuilderDropdown = {
    template: `
        <div class='row'>
        <div class="col-sm-3">
        <select name="page_builder_template_id" class="form-control" v-model="selectedPage">

            <option value="">
                None
            </option>
            <option v-for="page in pages" :value="page.id">
                {{page.title}}
            </option>
        </select>
        </div>
        <a :href="'/admin/pagebuilder/editpage/' + selectedPage + '?sku=' + productSku"  class="mt10" v-show="selectedPage" target="_blank" >
        Edit page
        </a>
       <span v-show="selectedPage">
            |
       </span>
       <a @click="newProductPage()" class="mt10">
            Create New
        </a>
        </div>
    `,
    props: ['pageId', 'productSku'],
    data() {
        return {
            pages: [],
            selectedPage: "",
        }
    },
    created() {
        this.fetchProductPageBuilders()
        this.selectedPage = this.pageId
    },
    methods: {
        async fetchProductPageBuilders() {
            const { data } = await axios.get('/admin/pagebuilder/products');
            this.pages = data;
        },
        async newProductPage() {
            const request = {
                'title': this.productSku,
                'slug': this.productSku,
                'category': "product"
            }
            const {data} = (await axios.post('/admin/pagebuilder/', request)).data;
            console.log(data);
            await this.fetchProductPageBuilders();
            this.selectedPage = data.page_id
        }
    }
}
const app = new Vue({
  el: "#app",
  components: {
    ShippingInput,
    SubscriptionInput,
    TagsInput,
    WholesalePricing,
    ProductSetup,
    ProductRecipe,
    ProductPlans,
    PageBuilderDropdown
  },
})
