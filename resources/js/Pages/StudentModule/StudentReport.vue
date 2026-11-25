<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import LanguageSwitch from '@/Components/StudentModule/LanguageSwitch.vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, reactive, ref } from 'vue'
import { useT } from '@/composables/useT'

const props = defineProps({
    rows: { type: Object, required: true },
    filters: { type: Object, required: true },
    columns: { type: Array, default: () => [] },
    allColumns: { type: Array, default: () => [] },
    sessions: { type: Array, default: () => [] },
    groups: { type: Array, default: () => [] },
    statuses: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({ classNames: [], sections: [], religions: [] }) },
    canSeeBrn: { type: Boolean, default: false },
})

const t = useT()
const f = reactive({ ...props.filters })
const chosen = ref([...props.columns])
const showColumns = ref(false)

const labelOf = (key) => props.allColumns.find((c) => c.key === key)?.label || key
const query = computed(() => {
    const q = { ...f, columns: chosen.value }
    Object.keys(q).forEach((k) => { if (q[k] === null || q[k] === '' || (Array.isArray(q[k]) && !q[k].length)) delete q[k] })
    return q
})

const run = () => router.get(route('sm.reports.index'), query.value, { preserveState: true, preserveScroll: true, replace: true })
const reset = () => { Object.assign(f, { class_name: null, section: null, group_id: null, gender: null, religion: null, status: null, admission: null, sort: 'roll' }); run() }
const link = (name, extra = {}) => route(name, { ...query.value, ...extra })

const input = 'h-9 w-full rounded-md border border-slate-300 bg-white px-3 text-sm focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-600/20 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100'
const label = 'mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400'
const btn = 'inline-flex h-9 items-center rounded-md border border-slate-300 bg-white px-3 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200'
</script>

<template>
    <Head :title="t('rep_title')" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-slate-50 text-slate-800 dark:bg-slate-950 dark:text-slate-200">
            <div class="mx-auto max-w-7xl px-4 py-5 sm:px-6 lg:py-7">

                <nav class="mb-2 text-xs text-slate-500 dark:text-slate-400" aria-label="Breadcrumb">{{ t('module') }} / <span class="font-medium text-slate-700 dark:text-slate-200">{{ t('rep_title') }}</span></nav>
                <header class="mb-4 flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 pb-4 dark:border-slate-800">
                    <h1 class="text-xl font-semibold text-slate-900 dark:text-white">{{ t('rep_title') }}</h1>
                    <div class="flex flex-wrap items-center gap-2">
                        <a :href="link('sm.reports.print')" target="_blank" rel="noopener" :class="btn">{{ t('pay_print') }}</a>
                        <a :href="link('sm.reports.export', { format: 'xlsx' })" :class="btn">Excel</a>
                        <a :href="link('sm.reports.export', { format: 'csv' })" :class="btn">CSV</a>
                        <LanguageSwitch />
                    </div>
                </header>

                <section class="mb-3 rounded-md border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <form class="grid grid-cols-2 gap-3 md:grid-cols-4 lg:grid-cols-6" @submit.prevent="run">
                        <div><label :class="label" for="session">{{ t('session') }}</label><select id="session" v-model="f.session" :class="input" @change="f.class_name = null; f.section = null; run()"><option v-for="y in sessions" :key="y" :value="y">{{ y }}</option></select></div>
                        <div><label :class="label" for="class">{{ t('class') }}</label><select id="class" v-model="f.class_name" :class="input" @change="run"><option :value="null">{{ t('all_classes') }}</option><option v-for="c in options.classNames" :key="c" :value="c">{{ c }}</option></select></div>
                        <div><label :class="label" for="section">{{ t('section') }}</label><select id="section" v-model="f.section" :class="input" @change="run"><option :value="null">—</option><option v-for="s in options.sections" :key="s" :value="s">{{ s }}</option></select></div>
                        <div><label :class="label" for="group">{{ t('ss_group') }}</label><select id="group" v-model="f.group_id" :class="input" @change="run"><option :value="null">—</option><option v-for="g in groups" :key="g.id" :value="g.id">{{ g.name }}</option></select></div>
                        <div><label :class="label" for="gender">{{ t('gender') }}</label><select id="gender" v-model="f.gender" :class="input" @change="run"><option :value="null">—</option><option>Male</option><option>Female</option><option>Other</option></select></div>
                        <div><label :class="label" for="religion">{{ t('religion') }}</label><select id="religion" v-model="f.religion" :class="input" @change="run"><option :value="null">—</option><option v-for="r in options.religions" :key="r" :value="r">{{ r }}</option></select></div>
                        <div><label :class="label" for="status">{{ t('status') }}</label><select id="status" v-model="f.status" :class="input" @change="run"><option :value="null">—</option><option v-for="(n, k) in statuses" :key="k" :value="k">{{ n }}</option></select></div>
                        <div><label :class="label" for="adm">{{ t('rep_admission') }}</label><select id="adm" v-model="f.admission" :class="input" @change="run"><option :value="null">—</option><option value="new">{{ t('rep_new') }}</option><option value="old">{{ t('rep_old') }}</option></select></div>
                        <div><label :class="label" for="sort">{{ t('rep_sort') }}</label><select id="sort" v-model="f.sort" :class="input" @change="run"><option value="roll">{{ t('roll') }}</option><option value="name">{{ t('name_en') }}</option><option value="code">{{ t('code') }}</option></select></div>
                        <div class="col-span-2 flex items-end gap-2 md:col-span-3 lg:col-span-3">
                            <button type="button" :class="btn" :aria-expanded="showColumns" @click="showColumns = !showColumns">{{ t('rep_columns') }} ({{ chosen.length }})</button>
                            <button type="button" :class="btn" @click="reset">{{ t('rep_reset') }}</button>
                        </div>
                    </form>

                    <fieldset v-if="showColumns" class="mt-3 border-t border-slate-200 pt-3 dark:border-slate-800">
                        <legend class="sr-only">{{ t('rep_columns') }}</legend>
                        <div class="flex flex-wrap gap-x-5 gap-y-2">
                            <label v-for="c in allColumns" :key="c.key" class="inline-flex items-center gap-1.5 text-sm"><input v-model="chosen" type="checkbox" :value="c.key" @change="run" />{{ c.label }}</label>
                        </div>
                        <p v-if="!canSeeBrn" class="mt-2 text-xs text-slate-500">{{ t('rep_brn_masked') }}</p>
                    </fieldset>
                </section>

                <p class="mb-2 text-sm text-slate-600 dark:text-slate-400">{{ rows.total.toLocaleString() }}{{ rows.capped ? '+' : '' }} {{ t('rep_students') }}</p>

                <section class="overflow-hidden rounded-md border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <div class="overflow-x-auto"><table class="w-full text-sm">
                        <thead class="bg-slate-100 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:bg-slate-800 dark:text-slate-300"><tr><th v-for="c in columns" :key="c" class="whitespace-nowrap px-3 py-2.5">{{ labelOf(c) }}</th></tr></thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                            <tr v-for="r in rows.data" :key="r.id" class="hover:bg-slate-50 dark:hover:bg-slate-800/50"><td v-for="c in columns" :key="c" class="px-3 py-2" :class="['code', 'brn', 'guardian_mobile'].includes(c) ? 'whitespace-nowrap font-mono text-xs' : ''">{{ r[c] ?? '' }}</td></tr>
                            <tr v-if="!rows.data.length"><td :colspan="columns.length" class="px-4 py-10 text-center text-slate-500">{{ t('rep_none') }}</td></tr>
                        </tbody>
                    </table></div>
                    <div v-if="rows.links && rows.links.length > 2" class="flex flex-wrap justify-center gap-1 border-t border-slate-200 px-4 py-3 dark:border-slate-800">
                        <template v-for="(link, i) in rows.links" :key="i">
                            <span v-if="link.url === null" class="rounded-md border border-slate-200 px-3 py-1.5 text-sm text-slate-300 dark:border-slate-800" v-html="link.label"></span>
                            <Link v-else :href="link.url" preserve-scroll preserve-state :class="['rounded-md border px-3 py-1.5 text-sm font-medium', link.active ? 'border-blue-700 bg-blue-700 text-white' : 'border-slate-300 bg-white text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200']" v-html="link.label" />
                        </template>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
