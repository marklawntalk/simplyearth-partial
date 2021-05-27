<template>
  <section v-if="timeLeft" class="pd0 bg-secondary navbar-fixed-top">
    <div class="row eq-height promo-bar">
      <div class="col-md-12 hidden-xs hidden-sm text-center text-white vertical-align">
        <h4 class="offer-container inline-block">
          <span>{{ text ? text + ' •': name ? name + ' •' : ''}}</span>
        </h4>
        <div class="inline-block">
          <h4 class="offer-container inline-block">
            <span class="offer-span">
              <strong>{{ offer }}</strong> {{ offer ? ' •':''}}
            </span>
          </h4>
          <div class="inline-block">
            <div class="inline-block">
              <div class="inline-block">
                <div class="countdown-timers col-center-flex">
                  <ul class="list-inline">
                    <li>
                      <div class="circle text-center">
                        <strong>
                          <h4 class="pd0 mg0 days" v-text="timeLeft.days"></h4>
                        </strong>
                        <h6 class="pd0 mg0">DAYS</h6>
                      </div>
                    </li>
                    <li>
                      <div class="circle text-center">
                        <strong>
                          <h4 class="pd0 mg0 hours" v-text="timeLeft.hours"></h4>
                        </strong>
                        <h6 class="pd0 mg0">HOURS</h6>
                      </div>
                    </li>
                    <li>
                      <div class="circle text-center">
                        <strong>
                          <h4 class="pd0 mg0 minutes" v-text="timeLeft.minutes"></h4>
                        </strong>
                        <h6 class="pd0 mg0">MINUTES</h6>
                      </div>
                    </li>
                    <li>
                      <div class="circle text-center">
                        <strong>
                          <h4 class="pd0 mg0 seconds" v-text="timeLeft.seconds"></h4>
                        </strong>
                        <h6 class="pd0 mg0">SECONDS</h6>
                      </div>
                    </li>
                  </ul>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-xs-12 hidden-md hidden-lg text-center text-white vertical-align">
        <div class="inline-block md-offer-box">
          <h4 class="offer-container inline-block">
            <span class="nowrap">{{ text ? text + ' •' : name ? name + ' •' : ''}}</span>
          </h4>
        </div>
        <div class="inline-block">
          <div class="inline-block md-offer-box">
            <h4 class="offer-container inline-block">
              <span class="offer-span nowrap">
                <strong>{{ offer }}</strong> {{ offer ? ' •' : ''}}
              </span>
            </h4>
          </div>
          <div class="inline-block">
            <div class="countdown-timers col-center-flex">
              <ul class="list-inline">
                <li>
                  <div class="circle text-center">
                    <strong>
                      <h4 class="pd0 mg0 days" v-text="timeLeft.days"></h4>
                    </strong>
                    <h6 class="pd0 mg0">Days</h6>
                  </div>
                </li>
                <li>
                  <div class="circle text-center">
                    <strong>
                      <h4 class="pd0 mg0 hours" v-text="timeLeft.hours"></h4>
                    </strong>
                    <h6 class="pd0 mg0">Hours</h6>
                  </div>
                </li>
                <li>
                  <div class="circle text-center">
                    <strong>
                      <h4 class="pd0 mg0 minutes" v-text="timeLeft.minutes"></h4>
                    </strong>
                    <h6 class="pd0 mg0">Mins.</h6>
                  </div>
                </li>
                <li>
                  <div class="circle text-center">
                    <strong>
                      <h4 class="pd0 mg0 seconds" v-text="timeLeft.seconds"></h4>
                    </strong>
                    <h6 class="pd0 mg0">Sec.</h6>
                  </div>
                </li>
              </ul>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>
<script>
import Countdown from "countdown-js";

export default {
  name: "timer",
  props: ["end_date", "offer", "name", "text"],
  data() {
    return {
      timeLeft: null
    };
  },
  methods: {
    setPaddingTop(event) {
      document.body.style.paddingTop = document.querySelector('.navbar-fixed-top').clientHeight + "px";
    }
  },
  mounted() {
    Countdown.timer(
      new Date(this.end_date + " +0000"),
      timeLeft => {
        this.timeLeft = timeLeft;
      },
      () => {}
    );
      this.$nextTick(function() {
        window.addEventListener('resize', this.setPaddingTop);

        //Init
        this.setPaddingTop()
      })

  }
};
</script>