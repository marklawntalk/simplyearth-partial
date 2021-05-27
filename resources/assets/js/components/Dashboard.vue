<template>
  <div id="app-dashboard">
    <div class="wrapper wrapper-content">
      <div class="row" v-if="stats">
        <div class="col-lg-2">
          <div class="ibox float-e-margins">
            <div class="ibox-title">
              <h5>Subscribers</h5>
            </div>
            <div class="ibox-content">
              <h1 class="no-margins">{{ stats.subscribers_active_count }}</h1>
              <small>Active</small>
              <br />
              <br />
              <h1 class="no-margins">{{ stats.subscribers_count }}</h1>
              <small>Total</small>
              <div class="text-center">
                <form action="/admin/store/tools/export-subscribers">
                  <button
                    type="submit"
                    class="btn btn-primary"
                    style="margin-top:20px;"
                  >Download Report</button>
                </form>
              </div>
            </div>
          </div>

          <div class="ibox float-e-margins">
            <div class="ibox-title">
              <span class="label label-info pull-right"></span>
              <h5>Orders</h5>
            </div>
            <div class="ibox-content">
              <h1 class="no-margins">{{ stats.orders_count }}</h1>
              <small>Total orders</small>
              <div class="text-center">
                <form action="/admin/store/tools/export-orders">
                  <button
                    type="submit"
                    class="btn btn-primary"
                    style="margin-top:20px;"
                  >Export Order Report</button>
                </form>
              </div>
            </div>
          </div>
        </div>
        <div class="col-lg-10">
          <div class="charts row">
            <div class="chart col-lg-4" :key="index" v-for="(chart,index) in charts">
              <chart :init="chart"></chart>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
<script>
import Chart from "./charts/Chart.vue";

export default {
  name: "Dashboard",
  components: {
    Chart
  },
  data() {
    return {
      stats: null,
      charts: {}
    };
  },
  methods: {
    load() {
      axios.get("/admin/dashboard/charts").then(response => {
        this.charts = response.data;
      });
      axios.get("/admin/dashboard/stats").then(response => {
        this.stats = response.data;
      });
    }
  },
  created() {
    this.load();
  }
};
</script>