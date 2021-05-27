<template>
  <div class="ibox float-e-margins">
    <div class="ibox-title">
      <h5>
        <span v-html="chart.name"></span>
        <small class="m-l-md">
          <i class="fa fa-clock-o"></i>
          Updated {{ moment.unix(chart.last_update).fromNow() }}
        </small>
      </h5>
      <div class="ibox-tools">
        <a class="collapse-link" @click="closed=!closed">
          <i class="fa fa-chevron-up" :class="{'fa-chevron-down':closed}"></i>
        </a>
        <a @click="refreshChart">
          <i class="fa fa-refresh"></i>
        </a>
      </div>
    </div>
    <transition name="slide">
      <div class="ibox-content" v-if="!closed">
        <div v-if="chart.data.stats">
          <div class="row">
            <div class="m-b-sm col-sm-6" v-if="chart.data.stats.small_month_stat" v-for="stat in chart.data.stats.small_month_stat">
            <h4 class="no-margins text-grey">{{ stat.value }}</h4>
            <small>{{ stat.label }}</small>
          </div>
          </div>
          <div class="row">
            <div class="m-b-sm col-sm-6" v-if="chart.data.stats.this_month_stat" v-for="stat in chart.data.stats.this_month_stat">
                <h1 :class="['no-margins', {'text-navy': stat.style=='up'}, {'text-danger': stat.style=='down'}]">
                  <span v-if="stat.style=='up'"><i class="fa fa-play fa-rotate-270"></i> {{ stat.value }}</span>
                  <span v-else-if="stat.style=='down'"><i class="fa fa-play fa-rotate-90"></i> {{ stat.value }}</span>
                  <span v-else>{{ stat.value }}</span></h1>
              <small v-if="stat.label" v-text="stat.label"></small>
            </div> 
          </div>          
        </div>
            <div>
              <line-chart v-if="chart.type == 'line'" :chart-data="chart.data" :options="chart.data.options"></line-chart>
              <bar-chart v-if="chart.type == 'bar'" :chart-data="chart.data" :options="chart.data.options"></bar-chart>
              <pie-chart v-if="chart.type == 'pie'" :chart-data="chart.data" :options="chart.data.options"></pie-chart>
              <horizontal-bar-chart
                v-if="chart.type == 'horizontalbar'"
                :chart-data="chart.data"
                :options="chart.options"
              ></horizontal-bar-chart>
            </div>
            
        <div class="chart-bottom" v-if="chart.data.bottom" v-html="chart.data.bottom"></div>
      </div>
    </transition>
  </div>
</template>
<script>
import BarChart from "./BarChart";
import LineChart from "./LineChart";
import PieChart from "./PieChart";
import HorizontalBarChart from "./HorizontalBarChart";

export default {
  components: {
    BarChart,
    HorizontalBarChart,
    LineChart,
    PieChart,
  },
  props: ["init"],
  methods: {
    refreshChart() {
      axios
        .post("/admin/dashboard/charts/refresh", {
          chart: { name: this.chart.name }
        })
        .then(response => {
          this.chart = response.data;
        });
    }
  },
  data() {
    return {
      chart: this.init,
      closed: false
    };
  }
};
</script>