<template>
  <div>
    <div class="row">
      <div class="col-sm-9">
        <div class="ibox" v-if="view == 'list'">
          <div class="ibox-content">
            <div class="input-group">
              <input
                type="text"
                placeholder="Search discounts"
                v-model="search_q"
                class="input form-control"
                @keyup="searchDiscounts"
              />
              <span class="input-group-btn">
                <button
                  type="button"
                  class="btn btn btn-primary"
                  @click="searchDiscounts"
                >
                  <i class="fa fa-search"></i> Search
                </button>
              </span>
              <div class="pull-right">
                <button
                  v-if="view == 'list'"
                  type="button"
                  @click="viewForm"
                  class="btn btn-primary m-r-sm"
                >
                  Add discount
                </button>
                <button
                  v-if="view == 'list'"
                  type="button"
                  @click="viewForm('bulk')"
                  class="btn btn-primary"
                >
                  Create Bulk Discounts
                </button>
              </div>
            </div>
          </div>
        </div>
        <div class="ibox">
          <div class="ibox-content" :class="{ 'sk-loading': loading }">
            <div class="sk-spinner sk-spinner-wave">
              <div class="sk-rect1"></div>
              <div class="sk-rect2"></div>
              <div class="sk-rect3"></div>
              <div class="sk-rect4"></div>
              <div class="sk-rect5"></div>
            </div>

            <div v-if="view == 'list'">
              <table
                class="table table-striped table-hover table-pointer"
                v-if="list"
              >
                <thead>
                  <tr>
                    <th>Code</th>
                    <th>Status</th>
                    <th>Used</th>
                    <th>Start</th>
                    <th>End</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="item in list.data" @click="viewItem(item.id)">
                    <td>
                      <span v-text="item.code"></span>
                    </td>
                    <td>{{ item.active ? "Active" : "—" }}</td>
                    <td>
                      {{ item.used }}
                      <span
                        v-if="
                          item.options.usage_limits &&
                          item.options.usage_limits > 0
                        "
                        >/{{ item.options.usage_limits }}</span
                      >
                    </td>
                    <td>
                      <span v-if="item.start_date">{{
                        moment(item.start_date)
                          .tz("America/Chicago")
                          .format("MMM D")
                      }}</span>
                      <span v-else>—</span>
                    </td>
                    <td>
                      <span v-if="item.end_date">{{
                        moment(item.end_date)
                          .tz("America/Chicago")
                          .format("MMM D")
                      }}</span>
                      <span v-else>—</span>
                    </td>
                  </tr>
                </tbody>
              </table>
              <Pagination
                :settings="list"
                :loader="loadList"
                :prevnextonly="true"
              ></Pagination>
            </div>
            <div v-if="view == 'form'">
              <fieldset class="form-horizontal">
                <div class="form-group" v-if="form.creation_type != 'bulk'">
                  <label class="col-sm-2 control-label">Code:</label>
                  <div class="col-sm-10">
                    <input
                      type="text"
                      class="form-control"
                      v-model="form.code"
                    />
                  </div>
                </div>
                <div class="form-group">
                  <label class="col-sm-2 control-label">Type:</label>
                  <div class="col-sm-10">
                    <div class="row">
                      <div class="col-sm-6">
                        <select v-model="form.type" class="form-control">
                          <option value="percentage">Percentage</option>
                          <option value="fixed_amount">Fixed Amount</option>
                          <option value="free_shipping">Free Shipping</option>
                          <option value="free_addon">Free Addon</option>
                          <option value="tiered_discount">
                            Tiered Discount
                          </option>
                          <option value="freefirstmonth">
                            Free First Month
                          </option>
                          <option value="free_product_plus_discount">
                            Free Oil with Percentage discount
                          </option>
                          <option value="subscription_box">
                            Subscription Box
                          </option>
                          <option value="bogo">BOGO</option>
                          <option value="special_offer_category">
                            Special Offer Category
                          </option>
                        </select>
                      </div>
                      <div
                        class="col-sm-6"
                        v-if="
                          form.type == 'percentage' ||
                          form.type == 'fixed_amount' ||
                          form.type == 'free_product_plus_discount' ||
                          form.type == 'bogo'
                        "
                      >
                        <div class="row">
                          <label class="col-sm-4 control-label"
                            >Discount value:</label
                          >
                          <div class="col-sm-4">
                            <div class="input-group m-b">
                              <span
                                class="input-group-addon"
                                v-if="form.type == 'fixed_amount'"
                                >$</span
                              >
                              <input
                                class="form-control"
                                type="text"
                                v-model="form.options.discount_value"
                              />
                              <span
                                class="input-group-addon"
                                v-if="
                                  form.type == 'percentage' ||
                                  form.type == 'free_product_plus_discount' ||
                                  form.type == 'bogo'
                                "
                                >%</span
                              >
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                <!--<div class="form-group">
                  <label class="col-sm-2 control-label">Campaign (optional):</label>
                  <div class="col-sm-10">
                    <input type="text" class="form-control" v-model="form.options.campaign" />
                  </div>
                </div>-->
                <div v-if="form.type == 'special_offer_category'">
                  <div class="form-group">
                    <label class="col-sm-2 control-label">Categories:</label>
                    <div class="col-sm-10">
                      <SearchLister
                        id="special-category-lister"
                        @updated="updateSpecialOfferCategoryList"
                        :init="form.special_offer_category_list"
                        :search_url="'/admin/categories/search'"
                        :placeholder="'Search categories'"
                      >
                        <template slot="option" slot-scope="option">
                          <div class="d-center">
                            <img :src="option.option.cover" class="img-sm" />
                            <span v-text="option.option.name"></span>
                          </div>
                        </template>

                        <template slot="list" slot-scope="option">
                          <img :src="option.list_item.cover" class="img-sm" />
                          <a href="#">
                            <span v-text="option.list_item.name"></span>
                          </a>
                        </template>
                      </SearchLister>
                    </div>
                  </div>
                  <div class="form-group">
                    <label class="col-sm-2 control-label">Rules:</label>
                    <div class="col-sm-10">
                      <label>Purchase</label>
                      <input
                        type="number"
                        v-model="form.options.special_offer_purchase"
                        min="0"
                        step="1"
                      />
                      <label>Receive</label>
                      <input
                        type="number"
                        v-model="form.options.special_offer_receive"
                        min="1"
                        step="1"
                      />
                      <label>Type</label>
                      <select v-model="form.options.special_offer_type">
                        <option value="fixed">Fixed Amount</option>
                        <option value="percentage">Percentage</option>
                      </select>
                      <label>Amount</label>
                      <input
                        type="number"
                        v-model="form.options.special_offer_discount_value"
                        min="0"
                      />
                    </div>
                  </div>
                </div>
                <div
                  class="form-group"
                  :class="{
                    hide:
                      form.type != 'free_addon' &&
                      form.type != 'percentage' &&
                      form.type != 'fixed_amount' &&
                      form.type != 'subscription_box',
                  }"
                >
                  <label class="col-sm-2 control-label">Addons:</label>
                  <div class="col-sm-10">
                    <div class="row">
                      <div class="col-sm-6">
                        <SearchLister
                          @updated="updateAddonList"
                          :init="form.addons_list"
                          :search_url="'/admin/products'"
                          :placeholder="'Search products'"
                        >
                          <template slot="option" slot-scope="option">
                            <div class="d-center">
                              <img :src="option.option.cover" class="img-sm" />
                              <span v-text="option.option.name"></span>
                            </div>
                          </template>

                          <template slot="list" slot-scope="option">
                            <img :src="option.list_item.cover" class="img-sm" />
                            <a href="#">
                              <span v-text="option.list_item.name"></span>
                            </a>
                          </template>
                        </SearchLister>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="form-group" :class="{ hide: form.type != 'bogo' }">
                  <label class="col-sm-2 control-label">Bogo products:</label>
                  <div class="col-sm-10">
                    <div class="row">
                      <div class="col-sm-6">
                        <SearchLister
                          @updated="updateBogoRequiredList"
                          :init="form.bogo_required_list"
                          :search_url="'/admin/products'"
                          :placeholder="'Search products'"
                        >
                          <template slot="option" slot-scope="option">
                            <div class="d-center">
                              <img :src="option.option.cover" class="img-sm" />
                              <span v-text="option.option.name"></span>
                            </div>
                          </template>

                          <template slot="list" slot-scope="option">
                            <img :src="option.list_item.cover" class="img-sm" />
                            <a href="#">
                              <span v-text="option.list_item.name"></span>
                            </a>
                          </template>
                        </SearchLister>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="form-group" :class="{ hide: form.type != 'bogo' }">
                  <label class="col-sm-2 control-label"
                    >Bogo discounted products:</label
                  >
                  <div class="col-sm-10">
                    <div class="row">
                      <div class="col-sm-6">
                        <SearchLister
                          @updated="updateBogoList"
                          :init="form.bogo_list"
                          :search_url="'/admin/products'"
                          :placeholder="'Search products'"
                        >
                          <template slot="option" slot-scope="option">
                            <div class="d-center">
                              <img :src="option.option.cover" class="img-sm" />
                              <span v-text="option.option.name"></span>
                            </div>
                          </template>

                          <template slot="list" slot-scope="option">
                            <img :src="option.list_item.cover" class="img-sm" />
                            <a href="#">
                              <span v-text="option.list_item.name"></span>
                            </a>
                          </template>
                        </SearchLister>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="form-group" v-if="form.type == 'subscription_box'">
                  <div class="row">
                    <label class="col-sm-4 control-label"
                      >Discount value for the 1st box :</label
                    >
                    <div class="col-sm-2">
                      <div class="input-group m-b">
                        <span class="input-group-addon">$</span>
                        <input
                          class="form-control"
                          type="text"
                          v-model="form.options.discount_value_first_box"
                        />
                      </div>
                    </div>
                  </div>
                  <div class="row">
                    <label class="col-sm-4 control-label"
                      >Discount value for the future boxes:</label
                    >
                    <div class="col-sm-2">
                      <div class="input-group m-b">
                        <span class="input-group-addon">$</span>
                        <input
                          class="form-control"
                          type="text"
                          v-model="form.options.discount_value_future_boxes"
                        />
                      </div>
                    </div>
                  </div>
                  <div class="row">
                    <label class="col-sm-4 control-label"
                      >Number of future boxes:</label
                    >
                    <div class="col-sm-2">
                      <div class="input-group m-b">
                        <input
                          class="form-control"
                          type="number"
                          min="0"
                          step="1"
                          v-model="form.options.number_of_future_boxes"
                        />
                      </div>
                    </div>
                  </div>
                </div>

                <div class="form-group" v-if="form.type == 'tiered_discount'">
                  <label class="col-sm-2 control-label">Tiers:</label>
                  <div class="col-sm-10">
                    <div class="form-inline">
                      <button
                        class="btn btn-primary btn-xs m-b-xs"
                        @click="addNewTier"
                      >
                        <i class="fa fa-plus"></i>
                      </button>
                      <select
                        v-model="form.options.tiered_discount.type"
                        class="form-control"
                      >
                        <option value="percentage">Percentage</option>
                        <option value="fixed_amount">Fixed amount</option>
                        <option value="addon">Addon</option>
                        <option value="percentage_addon">
                          Percentage + Addon
                        </option>
                      </select>
                    </div>

                    <div class="row">
                      <div class="col-sm-2">
                        <b>Minimum Purchase</b>
                      </div>
                      <div class="col-sm-2">
                        <b v-if="form.options.tiered_discount.type != 'addon'"
                          >Discount</b
                        >
                        <b v-else>Product</b>
                      </div>
                    </div>
                    <div
                      class="row"
                      v-for="(tier, index) in form.options.tiered_discount
                        .tiers"
                    >
                      <div class="col-sm-2">
                        <div class="input-group m-b">
                          <span class="input-group-addon">$</span>
                          <input
                            class="form-control input-sm"
                            type="text"
                            v-model="tier.min"
                          />
                        </div>
                      </div>
                      <div
                        v-if="form.options.tiered_discount.type != 'addon'"
                        class="col-sm-2"
                      >
                        <div class="input-group m-b">
                          <span
                            class="input-group-addon"
                            v-if="
                              form.options.tiered_discount.type ==
                              'fixed_amount'
                            "
                            >$</span
                          >
                          <input
                            class="form-control input-sm"
                            type="text"
                            v-model="tier.discount_value"
                          />
                          <span
                            v-if="
                              form.options.tiered_discount.type ==
                                'percentage' ||
                              form.options.tiered_discount.type ==
                                'percentage_addon'
                            "
                            class="input-group-addon"
                            >%</span
                          >
                        </div>
                      </div>
                      <div
                        v-if="
                          form.options.tiered_discount.type == 'addon' ||
                          form.options.tiered_discount.type ==
                            'percentage_addon'
                        "
                        class="col-sm-4"
                      >
                        <SearchLister
                          @updated="updateTieredAddons"
                          :extra="{ index: index }"
                          :multiple="false"
                          :init="tier.free_addons || []"
                          :search_url="'/admin/products'"
                          :placeholder="'Search a product'"
                        >
                          <template slot="option" slot-scope="option">
                            <div class="d-center">
                              <img :src="option.option.cover" class="img-sm" />
                              <span v-text="option.option.name"></span>
                            </div>
                          </template>

                          <template slot="list" slot-scope="option">
                            <img :src="option.list_item.cover" class="img-sm" />
                            <a href="#">
                              <span v-text="option.list_item.name"></span>
                            </a>
                          </template>
                        </SearchLister>
                      </div>
                      <div class="col-sm-1">
                        <button
                          class="btn btn-danger btn-xs m-b-xs"
                          @click="removeTier(index)"
                        >
                          <i class="fa fa-minus"></i>
                        </button>
                      </div>
                    </div>
                  </div>
                </div>

                <div
                  class="form-group"
                  v-if="
                    form.type == 'percentage' ||
                    form.type == 'fixed_amount' ||
                    form.type == 'free_shipping' ||
                    form.type == 'free_addon'
                  "
                >
                  <label class="col-sm-2 control-label"
                    >Minimum Requirement:</label
                  >
                  <div class="col-sm-10">
                    <div>
                      <label>
                        <input
                          type="radio"
                          v-model="form.options.minimum_requirement"
                          value="none"
                        />
                        None
                      </label>
                    </div>
                    <div>
                      <label>
                        <input
                          type="radio"
                          v-model="form.options.minimum_requirement"
                          value="minimum_purchase_amount"
                        />
                        Minimum purchase amount
                      </label>
                    </div>
                    <div
                      v-if="
                        form.options.minimum_requirement ==
                        'minimum_purchase_amount'
                      "
                      class="row"
                    >
                      <div class="col-sm-2">
                        <div class="input-group m-b">
                          <span class="input-group-addon">$</span>
                          <input
                            class="form-control"
                            type="text"
                            v-model="form.options.minimum_purchase_amount"
                          />
                        </div>
                      </div>
                    </div>
                    <div>
                      <label>
                        <input
                          type="radio"
                          v-model="form.options.minimum_requirement"
                          value="minimum_quantity_items"
                        />
                        Minimum quantity of items
                      </label>
                    </div>
                    <div
                      v-if="
                        form.options.minimum_requirement ==
                        'minimum_quantity_items'
                      "
                      class="row"
                    >
                      <div class="col-sm-2">
                        <div class="input-group m-b">
                          <input
                            class="form-control"
                            type="text"
                            v-model="form.options.minimum_quantity_items"
                          />
                        </div>
                      </div>
                    </div>
                    <div>
                      <label>
                        <input
                          type="radio"
                          v-model="form.options.minimum_requirement"
                          value="products"
                        />
                        Products
                      </label>
                    </div>
                    <div
                      :class="{
                        hide: form.options.minimum_requirement != 'products',
                      }"
                    >
                      <div class="row">
                        <div class="col-sm-6">
                          <label class="m-b-sm control-label">Rule:</label>
                          <div class>
                            <div class="m-b-sm">
                              <select
                                v-model="form.options.minimum_products_and_or"
                                class="form-control"
                              >
                                <option value="and">
                                  Must have ALL the products below
                                </option>
                                <option value="or">
                                  Must have EITHER of the products below
                                </option>
                              </select>
                            </div>
                          </div>
                          <SearchLister
                            id="product-lister"
                            @updated="updateRequiredProductList"
                            :init="form.required_product_list"
                            :search_url="'/admin/products'"
                            :placeholder="'Search products'"
                          >
                            <template slot="option" slot-scope="option">
                              <div class="d-center">
                                <img
                                  :src="option.option.cover"
                                  class="img-sm"
                                />
                                <span v-text="option.option.name"></span>
                              </div>
                            </template>

                            <template slot="list" slot-scope="option">
                              <img
                                :src="option.list_item.cover"
                                class="img-sm"
                              />
                              <a href="#">
                                <span v-text="option.list_item.name"></span>
                              </a>
                            </template>
                          </SearchLister>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <div
                  class="form-group"
                  v-if="
                    form.type == 'percentage' ||
                    form.type == 'fixed_amount' ||
                    form.type == 'free_addon'
                  "
                >
                  <label class="col-sm-2 control-label">Applies to:</label>
                  <div class="col-sm-10">
                    <div class="row">
                      <div class="col-sm-6">
                        <select
                          v-model="form.options.applies_to"
                          class="form-control"
                        >
                          <option value="order">Entire order</option>
                          <option value="categories">
                            Specific categories
                          </option>
                          <option value="products">Specific products</option>
                        </select>
                      </div>
                      <div class="col-sm-6">
                        <div
                          :class="{
                            hide: form.options.applies_to != 'categories',
                          }"
                        >
                          <SearchLister
                            id="category-lister"
                            @updated="updateCategoryList"
                            :init="form.category_list"
                            :search_url="'/admin/categories/search'"
                            :placeholder="'Search categories'"
                          >
                            <template slot="option" slot-scope="option">
                              <div class="d-center">
                                <img
                                  :src="option.option.cover"
                                  class="img-sm"
                                />
                                <span v-text="option.option.name"></span>
                              </div>
                            </template>

                            <template slot="list" slot-scope="option">
                              <img
                                :src="option.list_item.cover"
                                class="img-sm"
                              />
                              <a href="#">
                                <span v-text="option.list_item.name"></span>
                              </a>
                            </template>
                          </SearchLister>
                        </div>
                        <div
                          :class="{
                            hide: form.options.applies_to != 'products',
                          }"
                        >
                          <SearchLister
                            id="product-lister"
                            @updated="updateProductList"
                            :init="form.product_list"
                            :search_url="'/admin/products'"
                            :placeholder="'Search products'"
                          >
                            <template slot="option" slot-scope="option">
                              <div class="d-center">
                                <img
                                  :src="option.option.cover"
                                  class="img-sm"
                                />
                                <span v-text="option.option.name"></span>
                              </div>
                            </template>

                            <template slot="list" slot-scope="option">
                              <img
                                :src="option.list_item.cover"
                                class="img-sm"
                              />
                              <a href="#">
                                <span v-text="option.list_item.name"></span>
                              </a>
                            </template>
                          </SearchLister>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="form-group">
                  <label class="col-sm-2 control-label"
                    >Customer Eligibility:</label
                  >
                  <div class="col-sm-10">
                    <div>
                      <label>
                        <input
                          type="radio"
                          v-model="form.options.customer_eligibility"
                          value="everyone"
                        />
                        Everyone
                      </label>
                    </div>
                    <div>
                      <label>
                        <input
                          type="radio"
                          v-model="form.options.customer_eligibility"
                          value="specific_groups"
                        />
                        Specific groups of customers
                      </label>
                    </div>
                    <div
                      :class="{
                        hide:
                          form.options.customer_eligibility !=
                          'specific_groups',
                      }"
                    >
                      <vue-tags-input
                        v-model="customer_tag"
                        :tags="initialTags"
                        @tags-changed="
                          (newTags) =>
                            (form.options.customer_tags = newTags.map(
                              (tag) => tag.text
                            ))
                        "
                        :autocomplete-items="autocompleteItems"
                      />
                    </div>
                  </div>
                </div>

                <div class="form-group" v-if="form.creation_type != 'bulk'">
                  <label class="col-sm-2 control-label">Usage limits:</label>
                  <div class="col-sm-10">
                    <input
                      type="number"
                      step="1"
                      min="0"
                      class="form-control"
                      v-model="form.options.usage_limits"
                    />
                  </div>
                </div>
                <div class="form-group">
                  <label class="col-sm-2 control-label">Free shipping:</label>
                  <div class="col-sm-10">
                    <div class="checkbox checkbox-primary">
                      <input
                        type="checkbox"
                        class="form-control"
                        v-model="form.options.free_shipping"
                        value="1"
                      />
                      <label></label>
                    </div>
                  </div>
                </div>
                <div class="form-group" v-if="form.creation_type != 'bulk'">
                  <label class="col-sm-2 control-label"
                    >Limit to one use per customer:</label
                  >
                  <div class="col-sm-10">
                    <div class="checkbox checkbox-primary">
                      <input
                        type="checkbox"
                        class="form-control"
                        v-model="form.options.per_customer"
                        value="1"
                      />
                      <label></label>
                    </div>
                  </div>
                </div>

                <div class="form-group" v-if="form.creation_type != 'bulk'">
                  <label class="col-sm-2 control-label"
                    >For Monthly Subscription:
                  </label>
                  <div class="col-sm-10">
                    <div class="checkbox checkbox-primary">
                      <input
                        type="checkbox"
                        class="form-control"
                        v-model="form.options.is_subscription_box_only"
                        value="1"
                      />
                      <label></label>
                    </div>
                  </div>
                </div>

                <div
                  v-if="
                    form.creation_type != 'bulk' &&
                    form.options.is_subscription_box_only == 1
                  "
                >
                  <div class="form-group">
                    <label class="col-sm-2 control-label"
                      >Reactivation Only:
                    </label>
                    <div class="col-sm-10">
                      <div class="checkbox checkbox-primary">
                        <input
                          type="checkbox"
                          class="form-control"
                          v-model="form.options.is_reactivation_only"
                          value="1"
                        />
                        <label></label>
                      </div>
                    </div>
                  </div>

                  <!-- <div class="form-group">
                    <label class="col-sm-2 control-label"
                      >First order only:
                    </label>
                    <div class="col-sm-10">
                      <div class="checkbox checkbox-primary">
                        <input
                          type="checkbox"
                          class="form-control"
                          v-model="form.options.subscription_first_order"
                          value="1"
                        />
                        <label></label>
                      </div>
                    </div>
                  </div> -->
                </div>

                <div class="form-group" v-if="form.creation_type != 'bulk'">
                  <label class="col-sm-2 control-label"
                    >Limit to one use per IP address:</label
                  >
                  <div class="col-sm-10">
                    <div class="checkbox checkbox-primary">
                      <input
                        type="checkbox"
                        class="form-control"
                        v-model="form.options.per_ip_address"
                        value="1"
                      />
                      <label></label>
                    </div>
                  </div>
                </div>

                <div class="form-group">
                  <label class="col-sm-2 control-label">Active dates:</label>
                  <div class="col-sm-10">
                    <div class="row">
                      <div class="col-sm-6">
                        <label class="control-label">
                          Start
                          <small
                            v-if="form.start_date"
                            v-text="
                              'Time in wisconsin: ' +
                              wisconsinTime(form.start_date)
                            "
                          ></small>
                        </label>
                        <datepicker
                          :clearButton="true"
                          :bootstrap-styling="true"
                          :calendar-button="true"
                          :input-class="'form-control'"
                          v-model="form.start_date"
                        ></datepicker>
                      </div>
                      <div class="col-sm-6">
                        <label class="control-label">
                          End
                          <small
                            v-if="form.end_date"
                            v-text="
                              'Time in wisconsin: ' +
                              wisconsinTime(form.end_date)
                            "
                          ></small>
                        </label>
                        <datepicker
                          :clearButton="true"
                          :bootstrap-styling="true"
                          :calendar-button="true"
                          :input-class="'form-control'"
                          v-model="form.end_date"
                        ></datepicker>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="form-group" v-if="form.creation_type == 'bulk'">
                  <label class="col-sm-2 control-label"
                    >No. of discount codes to be generated (1-200):</label
                  >
                  <div class="col-sm-10">
                    <div class="row">
                      <div class="col-sm-4">
                        <input
                          type="number"
                          step="1"
                          min="1"
                          max="200"
                          class="form-control"
                          v-model="form.count"
                          value="1"
                        />
                      </div>
                    </div>
                  </div>
                </div>
                <div class="text-right">
                  <button
                    v-if="form.id"
                    type="button"
                    @click="deleteCode"
                    class="btn btn-danger pull-left"
                  >
                    Delete
                  </button>
                  <button
                    type="button"
                    @click="viewList"
                    class="btn btn-default"
                  >
                    Cancel
                  </button>
                  <button
                    type="button"
                    @click="submitForm"
                    class="btn btn-primary"
                  >
                    {{
                      form.creation_type == "bulk"
                        ? "Create and download discounts"
                        : "Save"
                    }}
                  </button>
                </div>
              </fieldset>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import moment from "moment-timezone";
import { FormClass } from "../components/Global";
import Pagination from "../components/Pagination.vue";
import Datepicker from "vuejs-datepicker";
import swal from "sweetalert";
import SearchLister from "../components/SearchLister.vue";
import VueTagsInput from "@johmun/vue-tags-input";

let form_init_settings = {
  type: "percentage",
  tiered_discount_addons_list: [],
  options: {
    usage_limits: 0,
    minimum_requirement: "none",
    customer_eligibility: "everyone",
    applies_to: "order",
    customer_tags: [],
    special_offer_type: "percentage",
    tiered_discount: {
      tiers: [],
      type: "fixed_amount",
      free_addons: [],
    },
  },
};
export default {
  name: "PageDiscount",
  components: {
    Pagination,
    Datepicker,
    SearchLister,
    VueTagsInput,
  },
  computed: {
    initialTags() {
      return _.map(this.form.options.customer_tags, (tag) => {
        return { text: tag };
      });
    },
  },
  methods: {
    wisconsinTime(date) {
      return moment(date).tz("America/Chicago").format("YYYY-MM-DD HH:mm:ss");
    },
    updateAddonList(list) {
      this.form.options.addons = _.map(list, "id");
    },

    updateBogoList(list) {
      this.form.options.bogo = _.map(list, "id");
    },

    updateBogoRequiredList(list) {
      this.form.options.bogo_required = _.map(list, "id");
    },

    updateTieredAddons(list, extra) {
      this.form.options.tiered_discount.tiers[extra.index].free_addons = _.map(
        list,
        "id"
      );
    },

    updateRequiredProductList(list) {
      this.form.options.required_products = _.map(list, "id");
    },
    updateProductList(list) {
      this.form.options.products = _.map(list, "id");
    },
    updateCategoryList(list) {
      this.form.options.categories = _.map(list, "id");
    },
    updateSpecialOfferCategoryList(list) {
      this.form.options.special_offer_categories = _.map(list, "id");
    },
    momentize(date) {
      return moment(date);
    },
    viewList() {
      this.view = "list";
    },
    viewItem(id) {
      this.loading = true;
      axios
        .get("/admin/discounts/" + id)
        .then((response) => {
          this.form.set(response.data);
          this.form.creation_type = "normal";
          this.view = "form";
        })
        .catch((error) => {})
        .then(() => {
          this.loading = false;
        });
    },
    viewForm(type) {
      this.form = new FormClass(form_init_settings);
      this.form.creation_type = type || "normal";
      if (this.form.creation_type == "bulk") {
        this.form.options.usage_limits = 1;
        this.form.count = 1;
        this.form.options.per_customer = 1;
      }
      this.view = "form";
    },
    loadList(page) {
      this.loading = true;
      axios
        .get("/admin/discounts/search", {
          params: {
            q: this.search_q,
            page: page || 1,
          },
        })
        .then((response) => {
          this.list = response.data;
        })
        .catch((error) => {})
        .then(() => {
          this.loading = false;
        });
    },
    submitForm() {
      this.loading = true;
      let method = "post";
      let url = "/admin/discounts/";

      if (this.form.id) {
        method = "patch";
        url += this.form.id;
      }
      axios[method](
        url,
        _.extend(this.form.get(), {
          customer_tags: this.form.options.customer_tags.map(
            (item) => item.text
          ),
        })
      )
        .then((response) => {
          if (response.data.csv) {
            window.open(
              "/admin/discounts/download/" + response.data.csv,
              "_blank"
            );
          }
          this.loadList();
          this.viewList();
        })
        .catch((error) => {
          this.form.errors.set(error.response.data);
        })
        .then(() => {
          this.loading = false;
        });
    },
    deleteCode() {
      swal({
        title: "Are you sure?",
        icon: "warning",
        buttons: true,
        dangerMode: true,
      }).then((willDelete) => {
        if (willDelete) {
          axios
            .delete("/admin/discounts/" + this.form.id)
            .then((response) => {
              swal("Discount code removed", {
                icon: "success",
              });
              this.loadList();
              this.viewList();
            })
            .catch((error) => {
              swal("Something went wrong", "error");
            });
        }
      });
    },

    searchDiscounts() {
      clearTimeout(this.debounce);
      this.debounce = setTimeout(() => {
        this.loadList();
      }, 350);
    },

    initTags() {
      if (this.customer_tag.length === 0) return;
      const url = `/admin/customers/tags/search/${this.customer_tag}`;

      clearTimeout(this.debounce);
      this.debounce = setTimeout(() => {
        axios
          .get(url)
          .then((response) => {
            this.autocompleteItems = _.map(response.data, (a) => {
              return { text: a.name };
            });
          })
          .catch(() => {
            console.warn("Oh. Something went wrong");
          });
      }, 350);
    },

    addNewTier() {
      this.form.options.tiered_discount.tiers.push({
        min: 0,
        discount_value: 0,
      });
    },
    removeTier(index) {
      this.form.options.tiered_discount.tiers.splice(index, 1);
    },
  },
  data() {
    return {
      view: "list",
      list: null,
      search_q: null,
      loading: false,
      customer_tag: "",
      autocompleteItems: [],
      form: new FormClass(form_init_settings),
    };
  },

  watch: {
    customer_tag: "initTags",
  },
  mounted() {
    this.loadList();
  },
};
</script>
