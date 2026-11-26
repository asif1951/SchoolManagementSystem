<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'

const props = defineProps({
    teachers: { type: Array, default: () => [] },
    years: { type: Array, default: () => [] },
    session: { type: String, default: '' },
    teacherId: { type: Number, default: null },
    source: { type: String, default: 'published' },
    grid: { type: Object, default: null },
    can: { type: Object, default: () => ({}) },
})

const pick = ref(props.teacherId ? String(props.teacherId) : '')
const src = ref(props.source)
const year = ref(props.session)
const go = () => router.get(route('routine.teachers'), { teacher: pick.value || undefined, session: year.value, source: src.value === 'draft' ? 'draft' : undefined }, { preserveState: true, replace: true })
watch(pick, go)

const cell = (day, col) => props.grid?.cells[`${day.id}|${col.key}`] || []
const printUrl = computed(() => (props.grid?.teacher ? route('routine.teachers.print', { teacher: props.grid.teacher.id, session: year.value, source: src.value === 'draft' ? 'draft' : undefined }) : null))
</script>

<template>
    <Head title="Teacher routines" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-slate-50 text-slate-800 dark:bg-slate-950 dark:text-slate-200">
            <div class="mx-auto max-w-6xl px-4 py-5 sm:px-6 lg:py-7">

                <nav class="mb-2 text-xs text-slate-500 dark:text-slate-400" aria-label="Breadcrumb">Routine / <span class="font-medium text-slate-700 dark:text-slate-200">{{ can.manage ? 'Teacher routines' : 'My routine' }}</span></nav>

                <header class="mb-5 flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 pb-4 dark:border-slate-800">
                    <div>
                        <h1 class="text-xl font-semibold text-slate-900 dark:text-white">{{ can.manage ? 'Teacher routines' : 'My routine' }}</h1>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">A teacher's whole week across every class, from the published routines.</p>
                    </div>
                    <a v-if="printUrl" :href="printUrl" target="_blank" rel="noopener" class="btn">Print</a>
                </header>

                <section class="panel mb-4 p-4">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-4">
                        <div>
                            <label class="field-label" for="y">Academic year</label>
                            <select id="y" v-model="year" class="field" @change="go"><option v-for="y in years" :key="y" :value="y">{{ y }}</option></select>
                        </div>
                        <div v-if="can.manage" class="sm:col-span-2">
                            <label class="field-label" for="t">Teacher</label>
                            <select id="t" v-model="pick" class="field"><option value="">Choose a teacher</option><option v-for="t in teachers" :key="t.id" :value="String(t.id)">{{ t.name }}</option></select>
                        </div>
                        <div v-if="can.manage">
                            <label class="field-label" for="s">Show</label>
                            <select id="s" v-model="src" class="field" @change="go"><option value="published">Published routines</option><option value="draft">Drafts as they stand</option></select>
                        </div>
                    </div>
                </section>

                <p v-if="!grid" class="panel px-4 py-12 text-center text-sm text-slate-500">{{ can.manage ? 'Choose a teacher to see their week.' : 'There is no routine for you yet.' }}</p>

                <section v-else class="panel overflow-hidden">
                    <div class="flex items-center justify-between border-b border-slate-200 px-4 py-2.5 dark:border-slate-800">
                        <h2 class="text-sm font-semibold">{{ grid.teacher?.name }}</h2>
                        <span class="text-sm text-slate-500">{{ grid.periods_per_week }} {{ grid.periods_per_week === 1 ? 'period' : 'periods' }} a week</span>
                    </div>
                    <p v-if="!grid.columns.length" class="px-4 py-10 text-center text-sm text-slate-500">No periods {{ src === 'published' ? 'published ' : '' }}for this teacher yet.</p>
                    <div v-else class="overflow-x-auto">
                        <table class="plain w-full border-collapse text-sm" :style="{ minWidth: 140 + grid.columns.length * 130 + 'px' }">
                            <thead>
                                <tr class="bg-slate-50 dark:bg-slate-900">
                                    <th class="w-32 border-b border-r border-slate-200 px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:border-slate-800">Day</th>
                                    <th v-for="c in grid.columns" :key="c.key" class="border-b border-slate-200 px-2 py-2 text-center text-xs font-semibold tracking-wide text-slate-600 dark:border-slate-800">{{ c.time }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="d in grid.days" :key="d.id" class="border-b border-slate-200 last:border-0 dark:border-slate-800">
                                    <th class="border-r border-slate-200 px-3 py-3 text-left text-sm font-semibold dark:border-slate-800">{{ d.en }}</th>
                                    <td v-for="c in grid.columns" :key="c.key" class="px-2 py-2 text-center align-middle">
                                        <div v-for="(x, i) in cell(d, c)" :key="i" class="leading-tight">
                                            <div class="text-sm font-medium text-slate-900 dark:text-white">{{ x.class }}</div>
                                            <div class="text-xs text-slate-500">{{ x.subject }}</div>
                                        </div>
                                        <span v-if="!cell(d, c).length" class="text-slate-300">—</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
