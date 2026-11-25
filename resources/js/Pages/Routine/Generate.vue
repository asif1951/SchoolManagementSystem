<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, reactive, ref } from 'vue'

const props = defineProps({
    years: { type: Array, default: () => [] },
    session: { type: String, default: '' },
    classes: { type: Array, default: () => [] },
    flash: { type: Object, default: () => ({}) },
})

const year = ref(props.session)
const find = ref('')
const chosen = ref(props.classes.filter((c) => c.planned > 0).map((c) => c.id))
const opts = reactive({ keep_existing: false, teacher_max_per_day: '' })
const preview = ref(null)
const loading = ref(false)
const error = ref('')

const shown = computed(() => props.classes.filter((c) => !find.value || c.label.toLowerCase().includes(find.value.toLowerCase())))
const allChosen = computed({
    get: () => shown.value.length > 0 && shown.value.every((c) => chosen.value.includes(c.id)),
    set: (v) => { const ids = shown.value.map((c) => c.id); chosen.value = v ? [...new Set([...chosen.value, ...ids])] : chosen.value.filter((id) => !ids.includes(id)) },
})
const withPlan = computed(() => chosen.value.filter((id) => props.classes.find((c) => c.id === id)?.planned > 0).length)

const changeYear = () => router.get(route('routine.generate'), { session: year.value }, { preserveState: false })

const body = (seed = null) => ({ class_ids: chosen.value, keep_existing: opts.keep_existing, teacher_max_per_day: opts.teacher_max_per_day || null, seed })

const generate = async (newSeed = false) => {
    loading.value = true
    error.value = ''
    try {
        const res = await window.axios.post(route('routine.generate.preview'), body(newSeed ? Math.floor(Math.random() * 2000000000) + 1 : (preview.value?.seed ?? null)))
        preview.value = res.data
    } catch (e) {
        preview.value = null
        error.value = e.response?.data?.message || 'The routines could not be made.'
    } finally {
        loading.value = false
    }
}

const rows = computed(() => (preview.value ? chosen.value.map((id) => ({ id, ...(preview.value.classes[id] || {}) })) : []))
const okCount = computed(() => rows.value.filter((r) => !r.error && !(r.unplaced || []).length).length)
const usable = computed(() => rows.value.filter((r) => !r.error && (r.placed + r.kept) > 0).length)

const apply = () => router.post(route('routine.generate.apply'), body(preview.value.seed), { preserveScroll: true, onSuccess: () => { preview.value = null } })
const clear = () => { preview.value = null }
</script>

<template>
    <Head title="Make routines automatically" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-slate-50 text-slate-800 dark:bg-slate-950 dark:text-slate-200">
            <div class="mx-auto max-w-6xl px-4 py-5 sm:px-6 lg:py-7">

                <nav class="mb-2 text-xs text-slate-500 dark:text-slate-400" aria-label="Breadcrumb">
                    <Link :href="route('routine.index')" class="hover:underline">Routine</Link> / <span class="font-medium text-slate-700 dark:text-slate-200">Make routines automatically</span>
                </nav>

                <header class="mb-5 border-b border-slate-200 pb-4 dark:border-slate-800">
                    <h1 class="text-xl font-semibold text-slate-900 dark:text-white">Make routines automatically</h1>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Choose the classes and the system builds their routines together, so a teacher who teaches in several classes is never in two places at once. Each class needs its weekly plan: open the class and use <b>Make it automatically</b> to set it once.</p>
                </header>

                <div v-if="flash.success" class="mb-4 rounded-md border border-l-4 border-slate-200 border-l-green-600 bg-white px-4 py-3 text-sm dark:border-slate-700 dark:bg-slate-900" role="status">{{ flash.success }}</div>
                <div v-if="flash.error" class="mb-4 rounded-md border border-l-4 border-slate-200 border-l-amber-500 bg-white px-4 py-3 text-sm dark:border-slate-700 dark:bg-slate-900" role="alert">{{ flash.error }}</div>

                <section class="panel mb-4 p-4">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-4">
                        <div><label class="field-label" for="g-year">Academic year</label><select id="g-year" v-model="year" class="field" @change="changeYear"><option v-for="y in years" :key="y" :value="y">{{ y }}</option></select></div>
                        <div class="sm:col-span-2"><label class="field-label" for="g-find">Find a class</label><input id="g-find" v-model="find" type="search" class="field" placeholder="Class, section or shift" /></div>
                        <div><label class="field-label" for="g-tmax">A teacher teaches at most (a day)</label><input id="g-tmax" v-model="opts.teacher_max_per_day" type="number" min="1" max="12" class="field" placeholder="no limit" @input="preview = null" /></div>
                    </div>
                    <label class="mt-3 inline-flex items-center gap-2 text-sm"><input v-model="opts.keep_existing" type="checkbox" class="rounded border-slate-300 text-brand-700 focus:ring-brand-600" @change="preview = null" />Keep the periods already placed (otherwise each chosen class's draft is replaced)</label>
                </section>

                <section class="panel mb-4 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="data-table min-w-[720px]">
                            <thead><tr>
                                <th class="w-8"><input v-model="allChosen" type="checkbox" class="rounded border-slate-300 text-brand-700 focus:ring-brand-600" aria-label="Choose all" /></th>
                                <th>Class and section</th><th>Shift · Medium</th><th>Class teacher</th><th>Weekly plan</th><th>Routine now</th><th class="num"></th>
                            </tr></thead>
                            <tbody>
                                <tr v-for="c in shown" :key="c.id">
                                    <td><input v-model="chosen" type="checkbox" :value="c.id" class="rounded border-slate-300 text-brand-700 focus:ring-brand-600" :aria-label="`Choose ${c.label}`" @change="preview = null" /></td>
                                    <td class="font-medium text-slate-900 dark:text-white">{{ c.label }}</td>
                                    <td class="whitespace-nowrap">{{ [c.shift, c.medium].filter(Boolean).join(' · ') || '—' }}</td>
                                    <td>{{ c.class_teacher || '—' }}</td>
                                    <td>
                                        <span v-if="c.planned" class="tabular-nums">{{ c.planned }} <span class="muted">of {{ c.capacity }} periods</span></span>
                                        <span v-else class="badge border-amber-200 bg-amber-50 text-amber-800">No plan yet</span>
                                    </td>
                                    <td class="tabular-nums">{{ c.filled }} <span class="muted">filled</span></td>
                                    <td class="num"><Link :href="route('routine.editor', c.id)" class="text-sm font-medium text-brand-700 hover:underline">{{ c.planned ? 'Open' : 'Set the plan' }}</Link></td>
                                </tr>
                                <tr v-if="!shown.length"><td colspan="7" class="px-4 py-10 text-center text-slate-500">No classes in this year.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <div v-if="error" class="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">{{ error }}</div>

                <div class="mb-4 flex flex-wrap items-center gap-2">
                    <button type="button" class="btn btn-primary" :disabled="loading || !withPlan" @click="generate(false)">{{ loading ? 'Making the routines…' : (preview ? 'Make them again' : `Make routines for ${withPlan} ${withPlan === 1 ? 'class' : 'classes'}`) }}</button>
                    <button v-if="preview" type="button" class="btn" :disabled="loading" @click="generate(true)">Try another arrangement</button>
                    <span v-if="chosen.length && withPlan < chosen.length" class="text-sm text-amber-800">{{ chosen.length - withPlan }} chosen {{ chosen.length - withPlan === 1 ? 'class has' : 'classes have' }} no plan and will be skipped.</span>
                </div>

                <section v-if="preview" class="panel overflow-hidden">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-2.5 dark:border-slate-800">
                        <p class="text-sm font-semibold">Result <span class="font-normal text-slate-500">· {{ okCount }} of {{ rows.length }} fit completely</span></p>
                        <span class="badge" :class="preview.ok ? 'border-green-200 bg-green-50 text-green-800' : 'border-amber-200 bg-amber-50 text-amber-800'">{{ preview.ok ? 'Everything fits' : 'Some periods do not fit' }}</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="data-table min-w-[640px]">
                            <thead><tr><th>Class</th><th class="num">Placed</th><th class="num">Planned</th><th>What did not fit</th></tr></thead>
                            <tbody>
                                <tr v-for="r in rows" :key="r.id">
                                    <td class="font-medium text-slate-900 dark:text-white">{{ r.label }}</td>
                                    <td class="num">{{ (r.placed || 0) + (r.kept || 0) }}</td>
                                    <td class="num">{{ r.needed || 0 }}</td>
                                    <td>
                                        <span v-if="r.error" class="text-red-700">{{ r.error }}</span>
                                        <ul v-else-if="(r.unplaced || []).length" class="space-y-0.5"><li v-for="u in r.unplaced" :key="u.subject" class="text-amber-800"><b>{{ u.subject }}</b>: {{ u.missing }} left out, because {{ u.why }}</li></ul>
                                        <span v-else class="text-green-700">Everything placed</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-200 px-4 py-3 dark:border-slate-800">
                        <p class="text-xs text-slate-500">These become drafts. Open any class to adjust it, then publish. Published copies are not touched.</p>
                        <div class="flex gap-2"><button type="button" class="btn" @click="clear">Discard</button><button type="button" class="btn btn-primary" :disabled="!usable" @click="apply">Use these routines ({{ usable }})</button></div>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
