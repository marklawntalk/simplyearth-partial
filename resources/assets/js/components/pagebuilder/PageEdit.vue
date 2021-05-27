<template>
  <div id="wrapper">
    <nav class="navbar-default navbar-static-side" role="navigation">
      <div class="sidebar-collapse">
        <div class="ibox-content">
          <h2>
            <button
              class="btn btn-primary"
              :class="{ 'btn-outline': preview_view != 'desktop'}"
              @click="preview_view = 'desktop'"
            >
              <i class="fa fa-desktop"></i>
            </button>
            <button
              class="btn btn-primary"
              :class="{ 'btn-outline': preview_view != 'tablet'}"
              @click="preview_view = 'tablet'"
            >
              <i class="fa fa-tablet"></i>
            </button>
            <button
              class="btn btn-primary"
              :class="{ 'btn-outline': preview_view != 'phone'}"
              @click="preview_view = 'phone'"
            >
              <i class="fa fa-mobile"></i>
            </button>
            <button @click="showSection" class="btn btn-primary dim pull-right" type="button">
              <i class="fa fa-plus"></i>
            </button>
          </h2>
        </div>
        <div class="ibox collapsed" style="margin-bottom: 0px !important;" v-if="!current_edit_section">
          <div class="ibox-title">
            <h5>Header Settings</h5>
            <div class="ibox-tools">
              <a class="collapse-link">
                <i class="fa fa-chevron-up"></i>
              </a>
            </div>
          </div>
          <div class="ibox-content" style="display: none;">
            <div class="form-group">
              <label for>Type</label>
              <select
                class="form-control"
                @change="updateSection"
                v-model="page_settings.header.type"
              >
                <option value="standard">Standard</option>
                <option value="landing">Landing Page</option>
              </select>
            </div>

            <div class="form-group">
              <input
                @change="updateSection"
                placeholder="Custom Button Link"
                type="text"
                class="form-control"
                v-model="page_settings.header.custom_button_link"
              />
            </div>

            <div class="form-group">
              <input
                @change="updateSection"
                placeholder="Custom Button Text"
                type="text"
                class="form-control"
                v-model="page_settings.header.custom_button_text"
              />
            </div>
          </div>
        </div>

        <div class="ibox collapsed" style="margin-bottom: 0px !important;" v-if="!current_edit_section">
          <div class="ibox-title">
            <h5>Footer Settings</h5>
            <div class="ibox-tools">
              <a class="collapse-link">
                <i class="fa fa-chevron-up"></i>
              </a>
            </div>
          </div>
          <div class="ibox-content" style="display: none;">
            <div class="form-group">
              <label for>Type</label>
              <select
                class="form-control"
                @change="updateSection"
                v-model="page_settings.footer.type"
              >
                <option value="standard">Standard</option>
                <option value="landing">Landing Page</option>
              </select>
            </div>
          </div>
        </div>

        <div class="ibox collapsed" style="margin-bottom: 0px !important;" v-if="!current_edit_section">
          <div class="ibox-title">
            <h5>Custom CSS</h5>
            <div class="ibox-tools">
              <a class="collapse-link">
                <i class="fa fa-chevron-up"></i>
              </a>
            </div>
          </div>
          <div class="ibox-content" style="display: none;">
            <div class="form-group">
              <textarea
                class="form-control"
                @change="updateSection"
                v-model="page_settings.other.custom_css"
              >
              </textarea>
            </div>
          </div>
        </div>

        <draggable
          class="nav metismenu"
          id="side-menu"
          v-model="sections"
          tag="ul"
          v-if="!current_edit_section"
          @update="updateSection"
          handle=".handle"
        >
          <li class="landing_link" v-for="(section,index) in sections" :key="index">

            <a class="text-pointer" @click="editSection(index)">
              <span class="pull-left handle">
                <span class="">
                  <i class="fa fa-arrows"></i>
                </span>
              </span>

              <span class="nav-label" v-text="section.label"></span>

            </a>
          </li>
        </draggable>
        <div v-else class="ibox-content">
          <div class="form-group">
            <button class="btn" @click="closeSection">
              <i class="fa fa-arrow-left"></i> Back
            </button>
          </div>

          <div
            class="form-group"
            v-for="(field,index) in current_edit_section.settings"
            :key="index"
          >
            <label v-text="field.label"></label>
            <input
              @change="updateSection"
              type="text"
              class="form-control"
              v-model="current_edit_section.settings[index].value"
              v-if="field.type=='link' || field.type=='text'"
            />
            <div
              v-if="field.type=='color'"
              class="row"
            >
            <div class="col-md-2">
                <input
                  @change="updateSection"
                  type="color"
                  class="form-control form-control--short"
                  v-model="current_edit_section.settings[index].value"
                />
            </div>
              <div class="col-md-4 form-group__color-value">
                <input
                  @change="updateSection"
                  type="text"
                  class="form-control"
                  v-model="current_edit_section.settings[index].value"
                />
              </div>
            </div>
            <textarea
              @change="updateSection"
              class="form-control"
              v-model="current_edit_section.settings[index].value"
              v-else-if="field.type=='textarea'"
            ></textarea>
            <select
              v-model="current_edit_section.settings[index].value"
              @change="updateSection"
              class="form-control"
              v-else-if="field.type=='choices'"
            >
              <option
                :value="option.value"
                v-for="(option,index) in field.options"
                :key="index"
              >{{ option.label }}</option>
            </select>
            <MediaLibrary
              v-if="field.type=='image'"
              @input="updateSection"
              :image_url="field.value"
              :media_id="index"
              v-model="current_edit_section.settings[index].value"
            >
              <div slot="front" slot-scope="image">
                <div class="dropzone">
                  <img :src="image.preview" class />

                  <p>Add or replace image</p>
                </div>
              </div>
            </MediaLibrary>
            <!-- Repeatable -->
            <Repeatable
              :list="current_edit_section.settings[index].value"
              v-if="field.type=='repeater'"
              :blank="current_edit_section.settings[index].children"
              @updated="repeatableUpdate($event, index)"
            >
              <template slot="add-button" slot-scope="props">
                <div class="form-group">
                  <button type="button" class="btn btn-primary" @click="props.addItem">
                    <i class="fa fa-plus"></i> Add new
                  </button>
                </div>
              </template>
              <template slot="list" slot-scope="props">
                <div class="form-group">
                  <div class>
                    <div class v-for="(item_fields,n) in props.item">
                      <label v-text="item_fields.label"></label>
                      <input
                        @change="updateSection"
                        type="text"
                        class="form-control"
                        v-model="props.repeatable[props.index][n].value"
                        v-if="item_fields.type=='link' || item_fields.type=='text'"
                      />
                      <textarea
                        @change="updateSection"
                        class="form-control"
                        v-model="props.repeatable[props.index][n].value"
                        v-else-if="item_fields.type=='textarea'"
                      ></textarea>
                      <select
                        v-model="props.repeatable[props.index][n].value"
                        @change="updateSection"
                        class="form-control"
                        v-else-if="item_fields.type=='choices'"
                      >
                        <option
                          :value="option.value"
                          v-for="(option,index) in item_fields.options"
                          :key="index"
                        >{{ option.label }}</option>
                      </select>
                      <MediaLibrary
                        v-if="item_fields.type=='image'"
                        @input="updateSection"
                        :image_url="item_fields.value"
                        :media_id="index+'-'+props.index"
                        v-model="props.repeatable[props.index][n].value"
                      >
                        <div slot="front" slot-scope="image">
                          <div class="dropzone">
                            <img :src="image.preview" class :alt="image.preview" />

                            <p>Add or replace image</p>
                          </div>
                        </div>
                      </MediaLibrary>
                    </div>
                    <div class>
                      <button
                        type="button"
                        class="btn btn-danger"
                        @click="props.removeItem(props.index)"
                      >
                        <i class="fa fa-minus"></i>
                      </button>
                    </div>
                  </div>
                </div>
              </template>
            </Repeatable>
          </div>




        <div class="">
          <hr />
          <div class="">
            <label>Section ID</label>
            <div class="form-group">
              <input type="text" class="form-control" v-model="current_edit_section.visibility.section_id" @change="updateSection">
            </div>
          </div>
          <div class="">
            <label>Visibility Settings</label>
          </div>
          <div class="">
            <Repeatable
              :list="current_edit_section.visibility.conditions"
              @updated="visibilityUpdate"
              :blank="{}"
            >
              <template slot="add-button" slot-scope="props">
                <div class="form-group">
                  <button type="button" class="btn btn-primary" @click="props.addItem">
                    <i class="fa fa-plus"></i> Add new condition
                  </button>
                </div>
              </template>
              <template slot="list" slot-scope="props">
                <div class="form-group">
                  <label for>Type</label>
                  <select class="form-control" v-model="props.repeatable[props.index].type">
                    <option value="parameter">Parameter</option>
                  </select>
                </div>
                <div class="" v-if="props.item.type=='parameter'">

                  <div class="form-group">
                    <label for>Parameter Name</label>
                    <input type="text" class="form-control" v-model="props.repeatable[props.index].parameter_name">
                  </div>

                  <div class="form-group">
                    <label for>Parameter Value</label>
                    <input type="text" class="form-control" v-model="props.repeatable[props.index].parameter_value">
                  </div>

                </div>
              </template>
            </Repeatable>
          </div>
        </div>

        </div>
      </div>
    </nav>
    <nav class="navbar-default navbar-static-side-bottom">
      <div class="ibox-content">
        <a href="/admin/pagebuilder" class="btn btn-default">Back to page list</a> <button v-if="current_edit_section" type="button" class="btn btn-sm btn-danger" @click="removeSection()"><i class="fa fa-remove"></i></button>
        <button class="btn btn-primary pull-right" @click="saveContent">Save changes</button>
      </div>
    </nav>
    <!-- Page wraper -->
    <div id="page-wrapper" class="gray-bg">
      <div id="app">
        <!-- Main view  -->

        <div class="pagebuilder-preview" :class="preview_view">
          <iframe :src="previewLink" width="100%" height="100%" />
        </div>
      </div>

      <!-- Footer -->
    </div>
    <!-- End page wrapper-->

    <div class="modal" :class="{ 'is-active' : show_section}">
      <div class="modal-background"></div>
      <div class="modal-card" style="width:100%;max-width:900px;">
        <header class="modal-card-head">
          <h3 class="modal-card-title">Choose a section template</h3>
          <button class="delete" @click="show_section = false" aria-label="close"></button>
        </header>
        <section class="modal-card-body">
          <p class="categories">
            <a
              class="btn btn-success btn-facebook m-r-xs"
              @click="category_filter = null"
              :class="{'btn-outline': category_filter }"
            >All</a>
            <a class="btn btn-success btn-facebook m-r-xs"
              @click="category_filter = category"
              :class="{'btn-outline': category_filter != category }"
              v-for="category in categories"
            >{{ category }}</a>
          </p>
          <hr />
          <div class="columns is-multiline">
            <div
              class="column is-one-quarter"
              v-for="(section,index) in filtered_templates"
              :key="index"
            >
              <button
                @click="addSection(section)"
                class="btn btn-primary btn-outline btn-block page-builder-template"
                v-text="section.label"
              ></button>
            </div>
          </div>
        </section>
      </div>
    </div>
  </div>
</template>
<script>
import draggable from "vuedraggable";
import MediaLibrary from "./../../components/MediaLibrary.vue";
import Repeatable from "./../../components/Repeatable.vue";

export default {
  name: "PageEdit",
  props: ["page", "templates"],
  components: {
    draggable,
    MediaLibrary,
    Repeatable
  },
  data() {
    return {
      preview_view: "desktop",
      edit_section_key: null,
      current_edit_section: null,
      current_edit_section_index: null,
      current_section_editing: false,
      show_section: false,
      sections: this.page.content || [],
      category_filter: null,
      page_settings: Object.assign(
        { header: { type: "standard" }, footer: { type: "standard" }, other: {custom_css: ""} },
        this.page.settings
      )
    };
  },
  computed: {
    categories() {
      let categories = [];

      this.templates.forEach(a => categories.push(...a.category));

      return new Set(categories);
    },
    filtered_templates() {
      if (this.category_filter) {
        return this.templates.filter(
          a => a.category.indexOf(this.category_filter) !== -1
        );
      }

      return this.templates;
    },
    previewLink() {
      if (!this.edit_section_key) {
        return "/pages/" + this.page.slug + "/preview";
      }

      return (
        "/pages/" + this.page.slug + "/preview?key=" + this.edit_section_key
      );
    }
  },
  methods: {
    editSection(index) {
      this.current_edit_section = Object.assign(
        { visibility: { conditions: {} } },
        JSON.parse(JSON.stringify(this.sections[index]))
      );
      this.current_edit_section_index = index;
    },
    showSection() {
      this.show_section = true;
    },
    addSection(section) {
      this.sections.push(section);
      this.show_section = false;
      this.submitSectionChanges();
    },
    removeSection() {

      swal({
        title: "Are you sure to delete this section?",
        icon: "warning",
        buttons: true,
        dangerMode: true
      }).then(willDelete => {
          if (willDelete) {
              this.sections.splice(this.current_edit_section_index, 1);
              this.submitSectionChanges();
              this.closeSection()
          }
      });


    },
    visibilityUpdate(value, index) {
      this.current_edit_section.visibility.conditions = value;
      this.updateSection();
    },
    repeatableUpdate(value, index) {
      this.current_edit_section.settings[index].value = value;
      this.updateSection();
    },
    updateSection() {
      this.sections[
        this.current_edit_section_index
      ] = this.current_edit_section;
      this.submitSectionChanges();
    },
    closeSection() {
      this.current_edit_section = null;
      this.current_edit_section_index = null;
    },
    saveContent() {
      this.saving = true;
      axios
        .patch("/admin/pagebuilder/editing/" + this.page.id, {
          sections: this.sections,
          settings: this.page_settings
        })
        .then(response => {
          swal({
            title: "Saved",
            icon: "success",
            timer: 2000
          });
        })
        .catch(() => {})
        .then(() => {
          this.saving = false;
        });
    },
    submitSectionChanges() {
      this.$nextTick(() => {
        this.current_section_editing = true;
        axios
          .post("/admin/pagebuilder/editing/" + this.page.id, {
            sections: this.sections,
            settings: this.page_settings
          })
          .then(response => {
            this.edit_section_key = response.data.key;
          })
          .catch(() => {})
          .then(() => {
            this.current_section_editing = false;
          });
      });
    }
  },
  mounted() {
    $(".collapse-link").on("click", function() {
      var ibox = $(this).closest("div.ibox");
      var button = $(this).find("i");
      var content = ibox.children(".ibox-content");
      content.slideToggle(200);
      button.toggleClass("fa-chevron-up").toggleClass("fa-chevron-down");
      ibox.toggleClass("").toggleClass("border-bottom");
      setTimeout(function() {
        ibox.resize();
        ibox.find("[id^=map-]").resize();
      }, 50);
    });

    // Close ibox function
    $(".close-link").on("click", function() {
      var content = $(this).closest("div.ibox");
      content.remove();
    });
  }
};
</script>
<style>
