<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import DonutChart from '../components/DonutChart.vue';
import HorizontalBarChart from '../components/HorizontalBarChart.vue';
import LoadingBlock from '../components/LoadingBlock.vue';
import PageHeading from '../components/PageHeading.vue';
import VerticalBarChart from '../components/VerticalBarChart.vue';
import { api } from '../lib/api';
import { can } from '../stores/auth';
import { setPageError } from '../stores/app';

const data = ref(null);
const loading = ref(false);
const filters = reactive({
    year: new Date().getFullYear(),
    mda_id: '',
    station_id: '',
    cadre_id: '',
    lga: '',
});

const money = new Intl.NumberFormat('en-NG', {
    style: 'currency',
    currency: 'NGN',
    maximumFractionDigits: 0,
    notation: 'compact',
});

const whole = new Intl.NumberFormat('en-NG');
const options = computed(() => data.value?.options ?? { years: [], mdas: [], stations: [], cadres: [], lgas: [] });
const stations = computed(() => options.value.stations.filter((station) => !filters.mda_id || Number(station.mda_id) === Number(filters.mda_id)));
const kpis = computed(() => data.value?.kpis ?? {});
const maximumWageTrend = computed(() => Math.max(...(data.value?.wage_bill.monthly_trend ?? []).map((row) => Number(row.total)), 1));
const maximumProjection = computed(() => Math.max(...(data.value?.wage_bill.five_year_projection ?? []).map((row) => Number(row.total)), 1));

const formatNumber = (value) => whole.format(Number(value ?? 0));
const formatMoney = (value) => money.format(Number(value ?? 0));
const insightValue = (item) => item.format === 'money' ? formatMoney(item.value) : item.value;

const fetchDashboard = async () => {
    if (!can('view-reports')) {
        setPageError('This action is unauthorized.');
        return;
    }

    loading.value = true;
    try {
        const params = Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '' && value !== null));
        data.value = (await api.get('/executive-dashboard', { params })).data.data;
        Object.assign(filters, data.value.filters);
        filters.mda_id ??= '';
        filters.station_id ??= '';
        filters.cadre_id ??= '';
        filters.lga ??= '';
    } finally {
        loading.value = false;
    }
};

const resetFilters = async () => {
    filters.year = new Date().getFullYear();
    filters.mda_id = '';
    filters.station_id = '';
    filters.cadre_id = '';
    filters.lga = '';
    await fetchDashboard();
};

watch(() => filters.mda_id, () => {
    if (filters.station_id && !stations.value.some((station) => Number(station.id) === Number(filters.station_id))) {
        filters.station_id = '';
    }
});

onMounted(fetchDashboard);
</script>

<template>
    <PageHeading
        eyebrow="Executive intelligence"
        title="Executive Workforce & Budget Intelligence Dashboard"
        description="State-wide workforce, wage bill, retirement, and service reporting intelligence for visible health MDAs."
    />

    <LoadingBlock v-if="!data && loading" />

    <template v-else-if="data">
        <section class="executive-filter-bar">
            <label class="civic-field">
                <span>Year</span>
                <select v-model="filters.year" @change="fetchDashboard">
                    <option v-for="year in options.years" :key="year" :value="year">{{ year }}</option>
                </select>
            </label>
            <label class="civic-field">
                <span>MDA</span>
                <select v-model="filters.mda_id" @change="fetchDashboard">
                    <option value="">All</option>
                    <option v-for="mda in options.mdas" :key="mda.id" :value="mda.id">{{ mda.code }} - {{ mda.name }}</option>
                </select>
            </label>
            <label class="civic-field">
                <span>LGA</span>
                <select v-model="filters.lga" @change="fetchDashboard">
                    <option value="">All</option>
                    <option v-for="lga in options.lgas" :key="lga" :value="lga">{{ lga }}</option>
                </select>
            </label>
            <label class="civic-field">
                <span>Facility</span>
                <select v-model="filters.station_id" @change="fetchDashboard">
                    <option value="">All</option>
                    <option v-for="station in stations" :key="station.id" :value="station.id">{{ station.name }}</option>
                </select>
            </label>
            <label class="civic-field">
                <span>Cadre</span>
                <select v-model="filters.cadre_id" @change="fetchDashboard">
                    <option value="">All</option>
                    <option v-for="cadre in options.cadres" :key="cadre.id" :value="cadre.id">{{ cadre.name }}</option>
                </select>
            </label>
            <button class="civic-button civic-button-primary" type="button" :disabled="loading" @click="resetFilters">Reset filters</button>
        </section>

        <section class="executive-kpi-grid">
            <article>
                <span>Total staff</span>
                <strong>{{ formatNumber(kpis.total_staff) }}</strong>
            </article>
            <article>
                <span>Active staff</span>
                <strong>{{ formatNumber(kpis.active_staff) }}</strong>
            </article>
            <article>
                <span>Retiring this year</span>
                <strong class="warning">{{ formatNumber(kpis.retiring_this_year) }}</strong>
            </article>
            <article>
                <span>Retiring in 3 years</span>
                <strong>{{ formatNumber(kpis.retiring_in_three_years) }}</strong>
            </article>
            <article>
                <span>Current monthly wage bill</span>
                <strong>{{ formatMoney(kpis.current_monthly_wage_bill) }}</strong>
            </article>
            <article>
                <span>Current annual wage bill</span>
                <strong>{{ formatMoney(kpis.current_annual_wage_bill) }}</strong>
            </article>
            <article>
                <span>Projected annual wage bill</span>
                <strong>{{ formatMoney(kpis.projected_annual_wage_bill) }}</strong>
            </article>
            <article>
                <span>MDAs reporting</span>
                <strong>{{ kpis.mdas_reporting.reported }}/{{ kpis.mdas_reporting.total }}</strong>
            </article>
        </section>

        <section class="executive-grid executive-grid-two">
            <article class="executive-panel">
                <div class="executive-panel-title">
                    <div><span>Staff attrition & retirement</span><h2>Retirement projection</h2></div>
                    <small>{{ filters.year }} - {{ Number(filters.year) + 4 }}</small>
                </div>
                <div class="executive-split">
                    <VerticalBarChart :rows="data.retirement.trend" :limit="5" />
                    <DonutChart :rows="data.retirement.attrition_breakdown" />
                </div>
            </article>

            <article class="executive-panel">
                <div class="executive-panel-title">
                    <div><span>Wage bill intelligence</span><h2>Trend and projection</h2></div>
                    <small>Estimated from current placements</small>
                </div>
                <div class="executive-line-grid">
                    <div>
                        <h3>Monthly wage bill</h3>
                        <div class="executive-line-chart">
                            <div v-for="row in data.wage_bill.monthly_trend" :key="row.label">
                                <i :style="{ height: `${Math.max((Number(row.total) / maximumWageTrend) * 100, 5)}%` }"></i>
                                <strong>{{ formatMoney(row.total) }}</strong>
                                <span>{{ row.label }}</span>
                            </div>
                        </div>
                    </div>
                    <div>
                        <h3>5-year projection</h3>
                        <div class="executive-line-chart">
                            <div v-for="row in data.wage_bill.five_year_projection" :key="row.label">
                                <i class="projection" :style="{ height: `${Math.max((Number(row.total) / maximumProjection) * 100, 5)}%` }"></i>
                                <strong>{{ formatMoney(row.total) }}</strong>
                                <span>{{ row.label }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </article>
        </section>

        <section class="executive-grid executive-grid-three">
            <article class="executive-panel">
                <div class="executive-panel-title"><div><span>Workforce composition</span><h2>Staff by cadre</h2></div></div>
                <HorizontalBarChart :rows="data.workforce.by_cadre" :limit="8" />
            </article>
            <article class="executive-panel">
                <div class="executive-panel-title"><div><span>Age profile</span><h2>Age band distribution</h2></div></div>
                <VerticalBarChart :rows="data.workforce.age_bands" :limit="5" />
            </article>
            <article class="executive-panel executive-alert-panel">
                <div class="executive-panel-title"><div><span>Executive insights & alerts</span><h2>Decision signals</h2></div></div>
                <div class="executive-alert-list">
                    <section v-for="alert in data.alerts" :key="alert.title">
                        <div>
                            <strong>{{ alert.title }}</strong>
                            <p>{{ alert.message }}</p>
                        </div>
                        <span>{{ alert.priority }}</span>
                    </section>
                </div>
            </article>
        </section>

        <section class="executive-grid executive-grid-two">
            <article class="executive-panel">
                <div class="executive-panel-title"><div><span>Wage bill by MDA</span><h2>Current monthly exposure</h2></div></div>
                <HorizontalBarChart :rows="data.wage_bill.by_mda" :limit="10" />
            </article>
            <article class="executive-panel">
                <div class="executive-panel-title">
                    <div><span>Service reporting & compliance</span><h2>Reporting performance</h2></div>
                    <small>{{ data.service_reporting.compliance_percent }}% compliance</small>
                </div>
                <section class="executive-compliance-row">
                    <div><span>Facilities submitted</span><strong>{{ data.service_reporting.facilities_submitted }}/{{ data.service_reporting.facilities_total }}</strong></div>
                    <div><span>Pending returns</span><strong>{{ data.service_reporting.pending_returns }}</strong></div>
                    <div><span>Late submissions</span><strong>{{ data.service_reporting.late_submissions }}</strong></div>
                </section>
                <VerticalBarChart :rows="data.service_reporting.trend" :limit="12" />
            </article>
        </section>

        <section class="executive-insight-strip">
            <article v-for="item in data.wage_bill.insights" :key="item.label" :data-tone="item.tone">
                <span>{{ item.label }}</span>
                <strong>{{ insightValue(item) }}</strong>
            </article>
        </section>
    </template>
</template>
