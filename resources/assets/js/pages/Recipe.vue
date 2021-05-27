<template>
    <div>
        <div class="row">
            <div class="col-sm-12">
                <div class="ibox">
                    <div class="ibox-content">
                        <div class="row">
                            <div class="col-sm-5">
                                <div class="input-group">
                                    <input type="text" placeholder="Search recipes" v-model="search_q"
                                           class="input form-control" @keyup="searchRecipe">
                                    <span class="input-group-btn">
                                        <button type="button" class="btn btn btn-primary" @click="searchRecipe">
                                            <i class="fa fa-search"></i> Search
                                        </button>
                                    </span>
                                </div>
                            </div>
                            <div class="col-sm-7">
                                <div class="pull-right">
                                    <button v-if="view==='list'" type="button" @click="viewForm"
                                            class="btn btn-primary m-r-sm">Add recipe
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="ibox">
                    <div class="ibox-content" :class="{'sk-loading':loading}">
                        <div class="sk-spinner sk-spinner-wave">
                            <div class="sk-rect1"></div>
                            <div class="sk-rect2"></div>
                            <div class="sk-rect3"></div>
                            <div class="sk-rect4"></div>
                            <div class="sk-rect5"></div>
                        </div>

                        <!-- LIST VIEW -->
                        <div v-if="view==='list'">
                            <table class="table table-striped table-hover table-pointer" v-if="list">
                                <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Created At</th>
                                    <th>Updated At</th>
                                </tr>
                                </thead>
                                <tbody>
                                <tr  v-for="item in list.data" @click="viewItem(item.id)">
                                    <td>{{item.name}}</td>
                                    <td>{{ formatDate(item.created_at)}}</td>
                                    <td>{{ formatDate(item.updated_at)}}</td>
                                </tr>
                                </tbody>
                            </table>
                            <Pagination :settings="list" :loader="loadList" :prevnextonly="true"></Pagination>
                        </div>

                        <!--EDIT VIEW-->
                        <div v-if="view==='form'">
                             <input type="hidden" id="testing-code" :value="'[recipe related='+form.slug+']'">

                            <fieldset class="form-horizontal">
                                <div class="form-group">
                                    <label class="col-sm-2 control-label">Name: </label>
                                    <div class="col-sm-5">
                                        <input type="text" class="form-control" v-model="form.name">
                                    </div>
                                </div>
                                <div class="form-group" v-if="products_list" v-for="product in products_list">

                                    <label class="col-sm-2 control-label">Product: </label>
                                    <div class="col-sm-5">
                                        <select class="form-control" v-model="product.id">
                                            <option v-for="(option, key) in options" :value="key">
                                                {{ option }}
                                            </option>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <div class="col-sm-5 col-sm-offset-2">
                                        <button type="button" class="btn btn-primary btn-xs pull-right" @click="addRow">
                                            <i class="fa fa-plus"></i> Add Recipe Products
                                        </button>

                                    <span class="btn btn-info text-white copy-btn ml-auto " @click.stop.prevent="copyTestingCode()">
                                        Copy to blog
                                    </span>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <button v-if="form.id" type="button" @click="deleteRecipe"
                                            class="btn btn-danger pull-left">Delete
                                    </button>
     
                                    <button type="button" @click="viewList" class="btn btn-default">Cancel</button>
                                    <button type="button" @click="submitRecipe" class="btn btn-primary">Save</button>
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
    import moment from "moment";
    import {FormClass} from "../components/Global";
    import Pagination from "../components/Pagination.vue";
    import swal from "sweetalert";

    export default {
        name: "PageRecipe",
        components: {
            Pagination
        },
        props: {
            recipeProducts: {
                type: Array,
                default: () => {
                    return [{id: ''}]
                }
            },
            products: {
                default: () => {
                    return {0:''}
                }
            }
        },
        mounted() {
            this.loadList();
        },
        data() {
            return {
                view: "list",
                list: null,
                search_q: null,
                loading: false,
                form: new FormClass({name: ""}),
                products_list: [{id:''}],
                options: JSON.parse(JSON.stringify(this.products)),
                testingCode: "1234"
            };
        },
        methods: {
            addRow() {
                this.products_list.push({id: ''});
            },
            removeRow(index) {
                this.products_list.splice(index, 1);
            },
            formatDate(date) {
                return moment(date).format("MMMM Do YYYY");
            },
            submitRecipe() {
                this.loading = true;
                let method = this.form.id ? "patch" : "post";
                let url = "/admin/recipes" + (this.form.id ? "/" + this.form.id : "");
                this.form.products = this.products_list.length ? this.products_list : [];
                console.log(this.form)
                axios[method](url, this.form.get())
                    .then(() => {
                        this.loadList();
                        this.viewList();
                    })
                    .catch(error => {
                        this.form.errors.set(error.response.data);
                    })
                    .then(() => {
                        this.loading = false;
                    });
            },
            deleteRecipe() {
                swal({
                    title: "Are you sure?",
                    text: "Once deleted, you will not be able to revert this!",
                    icon: "warning",
                    buttons: true,
                    dangerMode: true
                }).then(willDelete => {
                    if (willDelete) {
                        axios
                            .delete("/admin/recipes/" + this.form.id)
                            .then(response => {
                                swal("Recipe deleted.", {
                                    icon: "success"
                                });
                                this.loadList();
                                this.viewList();
                            })
                            .catch(error => {
                                swal("Something went wrong", "error");
                            });
                    }
                });
            },
            loadList(page) {
                this.loading = true;
                axios
                    .get("/admin/recipes/search", {
                        params: {
                            q: this.search_q,
                            page: page || 1
                        }
                    })
                    .then(response => {
                        this.list = response.data;
                    })
                    .catch(error => {
                    })
                    .then(() => {
                        this.loading = false;
                    });
            },
            searchRecipe() {
                clearTimeout(this.debounce);
                this.debounce = setTimeout(() => {
                    this.loadList();
                }, 350);
            },
            viewList() {
                this.view = "list";
            },
            viewForm() {
                this.form = new FormClass({name: ""});
                this.view = "form";
            },
            viewItem(id) {
                this.loading = true;
                axios
                    .get("/admin/recipes/" + id)
                    .then(response => {
                        // console.log(JSON.parse(response.data.ingredients));
                        // const userStr =JSON.parse(JSON.stringify(response.data.ingredients))
                        // console.log(userStr);
                        // this.products_list = response.data.products;
                        this.products_list = response.data.ingredients.length ? JSON.parse(response.data.ingredients) : response.data.products;
                        this.form.set(response.data);
                        this.form.creation_type = "normal";
                        this.view = "form";
                    })
                    .catch(error => {
                    })
                    .then(() => {
                        this.loading = false;
                    });
            },
                    copyTestingCode () {
                      let testingCodeToCopy = document.querySelector('#testing-code') 
                      testingCodeToCopy.setAttribute('type', 'text') 
                      testingCodeToCopy.select()

                      try {
                        var successful = document.execCommand('copy');
                        var msg = successful ? 'successful' : 'failed';
                        alert('Copying code has been ' + msg);
                      } catch (err) {
                        alert('Oops, unable to copy');
                      }

                      /* unselect the range */
                      testingCodeToCopy.setAttribute('type', 'hidden')
                      window.getSelection().removeAllRanges()
        },

        }
    };
    //TODO: add recipe products

</script>

<style scoped>
</style>
