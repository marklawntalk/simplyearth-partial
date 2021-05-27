import { HorizontalBar } from "vue-chartjs";

export default {
  extends: HorizontalBar,
  props: ["options"],
  mounted() {
    this.renderChart(this.chartData, this.options);
  },
};
