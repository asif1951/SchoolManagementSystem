<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import LanguageSwitch from '@/Components/StudentModule/LanguageSwitch.vue'
import { Head, router } from '@inertiajs/vue3'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useT } from '@/composables/useT'

const props = defineProps({
    sessions: { type: Array, default: () => [] },
    session: { type: String, default: '' },
    stats: { type: Object, required: true },
    statuses: { type: Object, default: () => ({}) },
})

const t = useT()
const year = ref(props.session)
const load = (extra = {}) => router.get(route('sm.statistics.index'), { session: year.value, ...extra }, { preserveState: true, replace: true })

// while a large school's figures are being built, look again every few seconds
let poll = null
onMounted(() => { poll = setInterval(() => { if (props.stats.building) router.reload({ only: ['stats'], preserveScroll: true }) }, 5000) })
onBeforeUnmount(() => clearInterval(poll))

const n = (v) => Number(v || 0).toLocaleString()
const pct = (part, whole) => (whole ? Math.round((part / whole) * 100) : 0)

const genderTotal = computed(() => Object.values(props.stats.gender).reduce((a, b) => a + b, 0))
const religionTotal = computed(() => Object.values(props.stats.religion).reduce((a, b) => a + b, 0))
const maxClass = computed(() => Math.max(1, ...props.stats.classes.map((c) => c.total)))
const sum = (key) => props.stats.classes.reduce((a, c) => a + c[key], 0)

const card = 'rounded-md border border-slate-200 bg-white px-4 py-3 dark:border-slate-800 dark:bg-slate-900'
const th = 'px-3 py-2'
</script>

<template>
    <Head :title="t('stat_title')" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-slate-50 text-slate-800 dark:bg-slate-950 dark:text-slate-200">
            <div class="mx-auto max-w-6xl px-4 py-5 sm:px-6 lg:py-7">

                <nav class="mb-2 text-xs text-slate-500 dark:text-slate-400" aria-label="Breadcrumb">{{ t('module') }} / <span class="font-medium text-slate-700 dark:text-slate-200">{{ t('stat_title') }}</span></nav>
                <header class="mb-4 flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 pb-4 dark:border-slate-800">
                    <h1 class="text-xl font-semibold text-slate-900 dark:text-white">{{ t('stat_title') }}</h1>
                    <div class="flex flex-wrap items-center gap-2">
                        <label class="sr-only" for="y">{{ t('session') }}</label>
                        <select id="y" v-model="year" class="h-9 rounded-md border border-slate-300 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900" @change="load()"><option v-for="y in sessions" :key="y" :value="y">{{ y }}</option></select>
                        <a :href="route('sm.statistics.print', { session: year })" target="_blank" rel="noopener" class="inline-flex h-9 items-center rounded-md border border-slate-300 bg-white px-3 text-sm font-medium dark:border-slate-700 dark:bg-slate-900">{{ t('pay_print') }}</a>
                        <button type="button" class="h-9 rounded-md border border-slate-300 bg-white px-3 text-sm font-medium dark:border-slate-700 dark:bg-slate-900" @click="load({ refresh: 1 })">{{ t('stat_refresh') }}</button>
                        <LanguageSwitch />
                    </div>
                </header>

                <p v-if="stats.building" class="mb-3 rounded-md border border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200" role="status">{{ stats.built_at ? t('stat_refreshing') : t('stat_building') }}</p>

                <div class="grid grid-cols-2 gap-3 md:grid-cols-5">
                    <div :class="card"><p class="text-xs text-slate-500">{{ t('stat_all') }}</p><p class="text-2xl font-semibold tabular-nums">{{ n(stats.total) }}</p></div>
                    <div :class="card"><p class="text-xs text-slate-500">{{ t('stat_active') }}</p><p class="text-2xl font-semibold tabular-nums text-green-700 dark:text-green-400">{{ n(stats.active) }}</p></div>
                    <div :class="card"><p class="text-xs text-slate-500">{{ t('rep_new') }}</p><p class="text-2xl font-semibold tabular-nums">{{ n(stats.new) }}</p></div>
                    <div :class="card"><p class="text-xs text-slate-500">{{ t('rep_old') }}</p><p class="text-2xl font-semibold tabular-nums">{{ n(stats.old) }}</p></div>
                    <div :class="card"><p class="text-xs text-slate-500">{{ t('stat_no_brn') }}</p><p class="text-2xl font-semibold tabular-nums" :class="stats.brn_missing ? 'text-amber-700 dark:text-amber-400' : ''">{{ n(stats.brn_missing) }}</p></div>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
                    <section :class="card">
                        <h2 class="mb-2 text-sm font-semibold">{{ t('status') }}</h2>
                        <ul class="space-y-1 text-sm"><li v-for="(v, k) in stats.by_status" :key="k" class="flex justify-between"><span>{{ statuses[k] || k }}</span><span class="tabular-nums">{{ n(v) }}</span></li></ul>
                    </section>
                    <section :class="card">
                        <h2 class="mb-2 text-sm font-semibold">{{ t('gender') }}</h2>
                        <ul class="space-y-2 text-sm"><li v-for="(v, k) in stats.gender" :key="k">
                            <div class="flex justify-between"><span>{{ k }}</span><span class="tabular-nums">{{ n(v) }} · {{ pct(v, genderTotal) }}%</span></div>
                            <div class="mt-1 h-2 rounded bg-slate-200 dark:bg-slate-700" role="presentation"><div class="h-2 rounded bg-blue-700 dark:bg-blue-400" :style="{ width: pct(v, genderTotal) + '%' }"></div></div>
                        </li></ul>
                    </section>
                    <section :class="card">
                        <h2 class="mb-2 text-sm font-semibold">{{ t('religion') }}</h2>
                        <ul class="space-y-2 text-sm"><li v-for="(v, k) in stats.religion" :key="k">
                            <div class="flex justify-between"><span>{{ k }}</span><span class="tabular-nums">{{ n(v) }} · {{ pct(v, religionTotal) }}%</span></div>
                            <div class="mt-1 h-2 rounded bg-slate-200 dark:bg-slate-700" role="presentation"><div class="h-2 rounded bg-teal-700 dark:bg-teal-400" :style="{ width: pct(v, religionTotal) + '%' }"></div></div>
                        </li></ul>
                    </section>
                </div>

                <section class="mt-4 overflow-hidden rounded-md border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="border-b border-slate-200 px-4 py-2.5 text-sm font-semibold dark:border-slate-800">{{ t('stat_by_class') }}</h2>
                    <div class="overflow-x-auto"><table class="w-full min-w-[640px] text-sm">
                        <thead class="bg-slate-100 text-left text-xs uppercase tracking-wide text-slate-600 dark:bg-slate-800 dark:text-slate-300"><tr><th :class="th">{{ t('class') }}</th><th :class="th">{{ t('section') }}</th><th :class="th">{{ t('ss_group') }}</th><th :class="[th, 'text-right']">{{ t('stat_boys') }}</th><th :class="[th, 'text-right']">{{ t('stat_girls') }}</th><th :class="[th, 'text-right']">{{ t('stat_other') }}</th><th :class="[th, 'w-1/4']">{{ t('stat_total') }}</th></tr></thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                            <tr v-for="c in stats.classes" :key="c.id"><td :class="th">{{ c.class }}</td><td :class="th">{{ c.section || '—' }}</td><td :class="th">{{ c.group || '—' }}</td><td :class="[th, 'text-right tabular-nums']">{{ c.male }}</td><td :class="[th, 'text-right tabular-nums']">{{ c.female }}</td><td :class="[th, 'text-right tabular-nums']">{{ c.other }}</td>
                                <td :class="th"><div class="flex items-center gap-2"><span class="w-9 text-right tabular-nums">{{ c.total }}</span><div class="h-2 flex-1 rounded bg-slate-200 dark:bg-slate-700" role="presentation"><div class="h-2 rounded bg-blue-700 dark:bg-blue-400" :style="{ width: pct(c.total, maxClass) + '%' }"></div></div></div></td></tr>
                            <tr v-if="!stats.classes.length"><td colspan="7" class="px-4 py-8 text-center text-slate-500">—</td></tr>
                        </tbody>
                        <tfoot v-if="stats.classes.length" class="bg-slate-50 font-semibold dark:bg-slate-800/60"><tr><td :class="th" colspan="3">{{ t('stat_total') }}</td><td :class="[th, 'text-right tabular-nums']">{{ sum('male') }}</td><td :class="[th, 'text-right tabular-nums']">{{ sum('female') }}</td><td :class="[th, 'text-right tabular-nums']">{{ sum('other') }}</td><td :class="th">{{ sum('total') }}</td></tr></tfoot>
                    </table></div>
                </section>

                <p class="mt-3 text-xs text-slate-500">{{ t('stat_built') }} {{ stats.built_at || '—' }}</p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
