<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'

const props = defineProps({
    years: { type: Array, default: () => [] },
    session: { type: String, default: '' },
    q: { type: String, default: '' },
    rows: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
    flash: { type: Object, default: () => ({}) },
    periodsReady: { type: Boolean, default: true },
    clashes: { type: Array, default: () => [] },
})

const f = reactive({ session: props.session, q: props.q })
const status = ref('')

const load = () => router.get(route('routine.index'), { session: f.session, q: f.q || undefined }, { preserveScroll: true, preserveState: true, replace: true })
let timer = null
watch(() => f.q, () => { clearTimeout(timer); timer = setTimeout(load, 300) })
onBeforeUnmount(() => clearTimeout(timer))

const shown = computed(() => props.rows.filter((r) => !status.value || r.status === status.value))
const counts = computed(() => props.rows.reduce((a, r) => { a[r.status] = (a[r.status] || 0) + 1; return a }, {}))

const badge = {
    empty: ['No routine', 'border-slate-200 bg-slate-100 text-slate-600'],
    draft: ['Draft', 'border-amber-200 bg-amber-50 text-amber-800'],
    published: ['Published', 'border-green-200 bg-green-50 text-green-800'],
    changed: ['Published · changes waiting', 'border-brand-200 bg-brand-50 text-brand-800'],
}
</script>

<template>
    <Head title="Class routines" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-slate-50 text-slate-800 dark:bg-slate-950 dark:text-slate-200">
            <div class="mx-auto max-w-7xl px-4 py-5 sm:px-6 lg:py-7">

                <nav class="mb-2 text-xs text-slate-500 dark:text-slate-400" aria-label="Breadcrumb">Routine / <span class="font-medium text-slate-700 dark:text-slate-200">Class routines</span></nav>

                <header class="mb-5 flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 pb-4 dark:border-slate-800">
                    <div>
                        <h1 class="text-xl font-semibold text-slate-900 dark:text-white">Class routines</h1>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            <template v-if="can.manage">Each class and section has its own routine. Open one to fill in its periods.</template>
                            <template v-else>The routines that have been published.</template>
                        </p>
                    </div>
                    <div v-if="can.manage" class="flex gap-2">
                        <Link :href="route('routine.teachers')" class="btn">Teacher routines</Link>
                        <Link :href="route('routine.periods')" class="btn">Periods &amp; days</Link>
                    </div>
                </header>

                <div v-if="flash.success" class="mb-4 rounded-md border border-l-4 border-slate-200 border-l-green-600 bg-white px-4 py-3 text-sm dark:border-slate-700 dark:bg-slate-900" role="status">{{ flash.success }}</div>
                <div v-if="flash.error" class="mb-4 rounded-md border border-l-4 border-slate-200 border-l-red-600 bg-white px-4 py-3 text-sm dark:border-slate-700 dark:bg-slate-900" role="alert">{{ flash.error }}</div>

                <section v-if="clashes.length" class="mb-3 rounded-md border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900" role="alert">
                    <p class="font-semibold">{{ clashes.length }} {{ clashes.length === 1 ? 'teacher is' : 'teachers are' }} in two classes at the same time</p>
                    <ul class="mt-1 list-disc space-y-0.5 pl-5">
                        <li v-for="(c, i) in clashes.slice(0, 8)" :key="i">{{ c.teacher }}: {{ c.day }} {{ c.time }}, {{ c.classes }}</li>
                    </ul>
                    <p class="mt-1 text-xs">These were saved before the rule was enforced. They cannot be published until one of the two periods is given to another teacher.</p>
                </section>

                <section class="panel mb-3 p-4">
                    <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                        <div>
                            <label class="field-label" for="r-year">Academic year</label>
                            <select id="r-year" v-model="f.session" class="field" @change="load"><option v-for="y in years" :key="y" :value="y">{{ y }}</option></select>
                        </div>
                        <div class="col-span-2">
                            <label class="field-label" for="r-q">Search</label>
                            <input id="r-q" v-model="f.q" type="search" class="field" placeholder="Class, section or title" />
                        </div>
                        <div v-if="can.manage">
                            <label class="field-label" for="r-status">Show</label>
                            <select id="r-status" v-model="status" class="field">
                                <option value="">All ({{ rows.length }})</option>
                                <option value="empty">No routine ({{ counts.empty || 0 }})</option>
                                <option value="draft">Draft ({{ counts.draft || 0 }})</option>
                                <option value="published">Published ({{ counts.published || 0 }})</option>
                                <option value="changed">Changes waiting ({{ counts.changed || 0 }})</option>
                            </select>
                        </div>
                    </div>
                </section>

                <div v-if="can.manage && !periodsReady" class="mb-3 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    The periods are not set up yet. <Link :href="route('routine.periods')" class="font-medium underline">Set the periods and working days first.</Link>
                </div>

                <section class="panel overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="data-table min-w-[760px]">
                            <thead>
                                <tr><th>Class and section</th><th>Shift · Medium</th><th>Class teacher</th><th>Periods filled</th><th>Status</th><th class="num">Actions</th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="r in shown" :key="r.id">
                                    <td>
                                        <Link v-if="can.manage" :href="route('routine.editor', r.id)" class="font-medium text-slate-900 hover:text-brand-700 hover:underline dark:text-white">{{ r.label }}</Link>
                                        <span v-else class="font-medium text-slate-900 dark:text-white">{{ r.label }}</span>
                                        <span v-if="r.group" class="badge ml-2 border-brand-200 bg-brand-50 text-brand-800">{{ r.group }}</span>
                                    </td>
                                    <td class="whitespace-nowrap">{{ [r.shift, r.medium].filter(Boolean).join(' · ') || '—' }}</td>
                                    <td>{{ r.class_teacher || '—' }}</td>
                                    <td class="tabular-nums">
                                        <template v-if="can.manage">{{ r.filled }} <span class="muted">of {{ r.total }}</span></template>
                                        <template v-else>—</template>
                                    </td>
                                    <td><span class="badge" :class="badge[r.status][1]">{{ badge[r.status][0] }}</span><span v-if="r.published_at" class="ml-2 text-xs text-slate-400">{{ r.published_at }}</span></td>
                                    <td class="num whitespace-nowrap">
                                        <Link v-if="can.manage" :href="route('routine.editor', r.id)" class="text-sm font-medium text-brand-700 hover:underline">{{ r.status === 'empty' ? 'Make routine' : 'Edit' }}</Link>
                                        <a v-if="r.status !== 'empty' || !can.manage" :href="route('routine.print', r.id)" target="_blank" rel="noopener" class="ml-3 text-sm font-medium text-slate-600 hover:underline">Print</a>
                                    </td>
                                </tr>
                                <tr v-if="!shown.length"><td colspan="6" class="px-4 py-12 text-center text-slate-500">{{ rows.length ? 'No class matches.' : (can.manage ? 'No classes in this year yet.' : 'No routine has been published yet.') }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
