<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import AutoFillDialog from '@/Components/Routine/AutoFillDialog.vue'
import ClassPeriodsDialog from '@/Components/Routine/ClassPeriodsDialog.vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'

const props = defineProps({
    grid: { type: Object, required: true },
    classes: { type: Array, default: () => [] },
    others: { type: Array, default: () => [] },
    teachers: { type: Array, default: () => [] },
    flash: { type: Object, default: () => ({}) },
    plan: { type: Object, default: null },
    errors: { type: Object, default: () => ({}) },
})

const g = computed(() => props.grid)
const cellKey = (day, period) => `${day}-${period}`
const cell = (day, period) => g.value.cells[cellKey(day, period)] || null
const teaching = computed(() => g.value.periods.filter((p) => !p.is_break))
const notPlaced = computed(() => g.value.subjects.filter((s) => !Object.values(g.value.cells).some((c) => c.subject === s.name)).map((s) => s.name))
const noTeacher = computed(() => g.value.subjects.filter((s) => !s.teacher_id).map((s) => s.name))

// ---- the class switcher -------------------------------------------------------------------------------------------
const goTo = ref(String(g.value.class.id))
watch(goTo, (id) => { if (String(id) !== String(g.value.class.id)) router.visit(route('routine.editor', id)) })

// ---- editing one cell ---------------------------------------------------------------------------------------------
const quick = ['Games', 'Library', 'Assembly', 'Moral Education', 'Computer Lab']
const open = ref(null)             // { day, period }
const form = reactive({ subject: '', label: '', teacher_id: '' })
const error = ref('')
const busy = ref(false)

const openCell = (day, period) => {
    const c = cell(day.id, period.id)
    open.value = { day, period }
    error.value = ''
    form.subject = c?.is_subject ? c.subject : ''
    form.label = c && !c.is_subject ? c.subject : ''
    form.teacher_id = c?.teacher_id ?? ''
}
const closeCell = () => { open.value = null; error.value = '' }

const assigned = (name) => g.value.subjects.find((s) => s.name === name) || null

const save = () => {
    if (!open.value || busy.value) return
    busy.value = true
    error.value = ''
    router.put(route('routine.cell', g.value.class.id), {
        day: open.value.day.id, period_id: open.value.period.id,
        subject: form.subject || null, label: form.subject ? null : (form.label || null), teacher_id: form.teacher_id || null,
    }, {
        preserveScroll: true, preserveState: true,
        onSuccess: closeCell,
        onError: (e) => { error.value = e.teacher_id || e.subject || e.cell || e.label || 'This could not be saved.' },
        onFinish: () => { busy.value = false },
    })
}

// one click on a subject fills the cell with that subject and the teacher the class module assigned to it
// a subject with one teacher fills the period at once; with several, the admin picks which of them takes this period
const pickSubject = (s) => {
    form.subject = s.name; form.label = ''; form.teacher_id = s.teacher_id ?? ''
    if ((s.teachers || []).length < 2) save()
}
const pickTeacher = (id) => { form.teacher_id = id; save() }
const pickLabel = (text) => { form.subject = ''; form.label = text; form.teacher_id = ''; save() }
const clearCell = () => { form.subject = ''; form.label = ''; form.teacher_id = ''; save() }

const onKey = (e) => { if (e.key === 'Escape') { if (dialog.value) dialog.value = null; else closeCell() } }
onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))

// ---- whole-routine actions ----------------------------------------------------------------------------------------
const dialog = ref(null)           // copyDay | copyFrom | clear | publish | unpublish
const copyFromDay = ref(null)
const copyTo = ref([])
const copyClass = ref('')

const askCopyDay = (day) => { copyFromDay.value = day; copyTo.value = []; dialog.value = 'copyDay' }
const doCopyDay = () => router.post(route('routine.copy-day', g.value.class.id), { from_day: copyFromDay.value.id, to_days: copyTo.value }, { preserveScroll: true, onSuccess: () => { dialog.value = null } })
const doCopyFrom = () => router.post(route('routine.copy-from', g.value.class.id), { from_class_id: copyClass.value }, { preserveScroll: true, onSuccess: () => { dialog.value = null } })
const doClear = () => router.delete(route('routine.clear', g.value.class.id), { preserveScroll: true, onSuccess: () => { dialog.value = null } })
const doPublish = () => router.post(route('routine.publish', g.value.class.id), {}, { preserveScroll: true, onSuccess: () => { dialog.value = null }, onError: (e) => { error.value = e.publish; dialog.value = null } })
const doUnpublish = () => router.delete(route('routine.unpublish', g.value.class.id), { preserveScroll: true, onSuccess: () => { dialog.value = null } })

const publishErr = computed(() => props.flash?.error)
const periodSourceText = computed(() => ({ class: 'its own periods', shift: 'the shift periods', common: 'the common periods' }[g.value.period_source]))
</script>

<template>
    <Head :title="`Routine — ${g.class.full_label}`" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-slate-50 text-slate-800 dark:bg-slate-950 dark:text-slate-200">
            <div class="mx-auto max-w-[1400px] px-4 py-5 sm:px-6 lg:py-7">

                <nav class="mb-2 text-xs text-slate-500 dark:text-slate-400" aria-label="Breadcrumb">
                    <Link :href="route('routine.index')" class="hover:underline">Class routines</Link> / <span class="font-medium text-slate-700 dark:text-slate-200">{{ g.class.full_label }}</span>
                </nav>

                <header class="mb-4 flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 pb-4 dark:border-slate-800">
                    <div class="min-w-0">
                        <h1 class="text-xl font-semibold text-slate-900 dark:text-white">{{ g.class.full_label }}</h1>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            Session {{ g.class.year }}<template v-if="g.class.shift"> · {{ g.class.shift }}</template><template v-if="g.class.medium"> · {{ g.class.medium }}</template><template v-if="g.class.group"> · {{ g.class.group }}</template>
                            · Class teacher: <b class="font-medium text-slate-700 dark:text-slate-200">{{ g.class.class_teacher || 'not assigned' }}</b>
                        </p>
                    </div>
                    <div class="flex flex-wrap items-end gap-2">
                        <div class="w-56">
                            <label class="field-label" for="goto">Go to another class</label>
                            <select id="goto" v-model="goTo" class="field"><option v-for="c in classes" :key="c.id" :value="String(c.id)">{{ c.label }}</option></select>
                        </div>
                    </div>
                </header>

                <!-- state of the routine and what can be done with it -->
                <section class="panel mb-4 flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                    <div class="flex flex-wrap items-center gap-3 text-sm">
                        <span class="badge" :class="g.published ? (g.changed ? 'border-brand-200 bg-brand-50 text-brand-800' : 'border-green-200 bg-green-50 text-green-800') : 'border-amber-200 bg-amber-50 text-amber-800'">
                            {{ g.published ? (g.changed ? 'Published · changes waiting' : 'Published') : 'Draft' }}
                        </span>
                        <span class="text-slate-500">{{ g.filled }} of {{ g.total }} periods filled</span>
                        <span class="text-slate-400">· {{ periodSourceText }}</span>
                        <span v-if="g.published" class="text-slate-400">published {{ g.published_at }}</span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" class="btn" @click="dialog = 'periods'">Periods &amp; breaks</button>
                        <button type="button" class="btn" @click="dialog = 'auto'">Make it automatically</button>
                        <button type="button" class="btn" @click="copyClass = ''; dialog = 'copyFrom'">Copy from another class</button>
                        <button type="button" class="btn" :disabled="!g.filled" @click="dialog = 'clear'">Empty it</button>
                        <a :href="route('routine.print', { class: g.class.id, source: 'draft' })" target="_blank" rel="noopener" class="btn">Print</a>
                        <button v-if="g.published" type="button" class="btn" @click="dialog = 'unpublish'">Unpublish</button>
                        <button type="button" class="btn btn-primary" :disabled="!g.filled || (g.published && !g.changed)" @click="dialog = 'publish'">{{ g.published ? 'Publish changes' : 'Publish' }}</button>
                    </div>
                </section>

                <div v-if="flash.success" class="mb-3 rounded-md border border-l-4 border-slate-200 border-l-green-600 bg-white px-4 py-3 text-sm dark:border-slate-700 dark:bg-slate-900" role="status">{{ flash.success }}</div>
                <div v-if="publishErr" class="mb-3 rounded-md border border-l-4 border-slate-200 border-l-amber-500 bg-white px-4 py-3 text-sm dark:border-slate-700 dark:bg-slate-900" role="alert">{{ publishErr }}</div>
                <div v-if="error && !open" class="mb-3 rounded-md border border-l-4 border-slate-200 border-l-red-600 bg-white px-4 py-3 text-sm dark:border-slate-700 dark:bg-slate-900" role="alert">{{ error }}</div>

                <p v-if="!teaching.length" class="panel px-4 py-10 text-center text-sm text-slate-500">
                    There are no periods for this shift yet. <Link :href="route('routine.periods')" class="font-medium text-brand-700 hover:underline">Set the periods first.</Link>
                </p>

                <!-- the routine: days down the side, periods across -->
                <section v-else class="panel overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="plain w-full border-collapse text-sm" style="min-width: 900px">
                            <thead>
                                <tr class="bg-slate-50 dark:bg-slate-900">
                                    <th class="sticky left-0 z-10 w-32 border-b border-r border-slate-200 bg-slate-50 px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:bg-slate-900">Day</th>
                                    <th v-for="p in g.periods" :key="p.id" class="border-b border-slate-200 px-2 py-2 text-center dark:border-slate-800" :class="p.is_break ? 'w-14 bg-slate-100 dark:bg-slate-800/60' : ''">
                                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300">{{ p.name }}</div>
                                        <div class="mt-0.5 whitespace-nowrap text-[11px] font-normal normal-case tracking-normal text-slate-400">{{ p.time }}</div>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="d in g.days" :key="d.id" class="border-b border-slate-200 last:border-0 dark:border-slate-800">
                                    <th class="sticky left-0 z-10 border-r border-slate-200 bg-white px-3 py-2 text-left align-top dark:border-slate-800 dark:bg-slate-900">
                                        <div class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ d.en }}</div>
                                        <button type="button" class="mt-1 text-[11px] font-medium normal-case text-brand-700 hover:underline" @click="askCopyDay(d)">Copy to other days</button>
                                    </th>
                                    <template v-for="p in g.periods" :key="p.id">
                                        <td v-if="p.is_break" class="bg-slate-100 text-center text-[11px] font-medium uppercase tracking-wide text-slate-400 dark:bg-slate-800/60">Break</td>
                                        <td v-else class="p-1 align-top">
                                            <button type="button" class="group flex h-[4.25rem] w-full flex-col items-start justify-center rounded-md border px-2.5 py-1.5 text-left transition-colors"
                                                    :class="[cell(d.id, p.id) ? 'border-slate-200 bg-white hover:border-brand-400 dark:border-slate-700 dark:bg-slate-900' : 'border-dashed border-slate-200 bg-transparent text-slate-300 hover:border-brand-400 hover:bg-brand-50 hover:text-brand-700 dark:border-slate-700',
                                                             open && open.day.id === d.id && open.period.id === p.id ? '!border-brand-600 ring-2 ring-brand-600/20' : '']"
                                                    :aria-label="`${d.en}, ${p.name} period`" @click="openCell(d, p)">
                                                <template v-if="cell(d.id, p.id)">
                                                    <span class="w-full truncate text-sm font-medium text-slate-900 dark:text-white">{{ cell(d.id, p.id).subject }}</span>
                                                    <span class="w-full truncate text-xs text-slate-500">{{ (cell(d.id, p.id).all_teachers || []).join(' / ') || (cell(d.id, p.id).is_subject ? 'no teacher' : '') }}</span>
                                                </template>
                                                <span v-else class="text-lg leading-none">+</span>
                                            </button>
                                        </td>
                                    </template>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <section class="panel overflow-hidden lg:col-span-2">
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-2.5 dark:border-slate-800">
                            <h2 class="text-sm font-semibold">Subjects and their teachers <span class="font-normal text-slate-400">· for session {{ g.class.year }}, from the class module</span></h2>
                            <Link :href="`/subject-teachers?year=${g.class.year}`" class="text-sm font-medium text-brand-700 hover:underline">Change the teachers</Link>
                        </div>
                        <table class="data-table">
                            <thead><tr><th>Subject</th><th>Teacher</th><th class="num">Periods a week</th></tr></thead>
                            <tbody>
                                <tr v-for="s in g.subjects" :key="s.name">
                                    <td class="font-medium text-slate-900 dark:text-white">{{ s.name }}</td>
                                    <td>{{ s.teacher || '' }}<span v-if="!s.teacher" class="text-amber-700">no teacher assigned</span></td>
                                    <td class="num">{{ Object.values(g.cells).filter((c) => c.subject === s.name).length }}</td>
                                </tr>
                                <tr v-if="!g.subjects.length"><td colspan="3" class="px-4 py-6 text-center text-slate-500">This class has no subjects yet.</td></tr>
                            </tbody>
                        </table>
                    </section>
                    <section class="panel p-4 text-sm">
                        <h2 class="mb-2 font-semibold">To check</h2>
                        <ul class="space-y-2 text-slate-600 dark:text-slate-300">
                            <li v-if="noTeacher.length"><b class="text-amber-700">No teacher assigned:</b> {{ noTeacher.join(', ') }}. <Link :href="`/subject-teachers?year=${g.class.year}`" class="font-medium text-brand-700 hover:underline">Assign in Subject Teachers ({{ g.class.year }})</Link></li>
                            <li v-if="notPlaced.length"><b>Not in the routine yet:</b> {{ notPlaced.join(', ') }}</li>
                            <li v-for="x in (g.plan_check?.over || [])" :key="x" class="text-red-700"><b>Against the plan:</b> {{ x }}</li>
                            <li v-if="(g.plan_check?.under || []).length" class="text-amber-800"><b>Still to place:</b> {{ g.plan_check.under.join(', ') }}</li>
                            <li v-if="!noTeacher.length && !notPlaced.length && !(g.plan_check?.over || []).length" class="text-green-700">Every subject has a teacher and a place in the week.</li>
                        </ul>
                    </section>
                </div>
            </div>
        </div>

        <!-- the cell being edited -->
        <div v-if="open" class="fixed inset-0 z-40 bg-slate-900/30" @mousedown.self="closeCell"></div>
        <aside v-if="open" class="fixed inset-y-0 right-0 z-50 flex w-full max-w-sm flex-col border-l border-slate-200 bg-white shadow-xl dark:border-slate-800 dark:bg-slate-900" role="dialog" aria-modal="true" :aria-label="`${open.day.en} ${open.period.name} period`">
            <div class="flex items-start justify-between border-b border-slate-200 px-5 py-4 dark:border-slate-800">
                <div>
                    <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ open.day.en }} · {{ open.period.name }} period</h2>
                    <p class="text-sm text-slate-500">{{ open.period.time }}</p>
                </div>
                <button type="button" class="text-xl leading-none text-slate-400 hover:text-slate-700" aria-label="Close" @click="closeCell">×</button>
            </div>

            <div class="flex-1 space-y-5 overflow-y-auto px-5 py-4">
                <p v-if="error" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">{{ error }}</p>

                <div>
                    <p class="field-label">Subject — click one to fill the period</p>
                    <div class="flex flex-wrap gap-2">
                        <button v-for="s in g.subjects" :key="s.name" type="button" :disabled="busy"
                                class="rounded-md border px-3 py-1.5 text-sm transition-colors"
                                :class="form.subject === s.name ? 'border-brand-700 bg-brand-700 text-white' : 'border-slate-300 bg-white text-slate-700 hover:border-brand-400 hover:text-brand-800 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200'"
                                @click="pickSubject(s)">{{ s.name }}</button>
                        <span v-if="!g.subjects.length" class="text-sm text-slate-500">This class has no subjects. Add them in the class module.</span>
                    </div>
                </div>

                <div v-if="form.subject">
                    <div v-if="(assigned(form.subject)?.teachers || []).length > 1" class="mb-3">
                        <p class="field-label">This subject has {{ assigned(form.subject).teachers.length }} teachers: who takes this period?</p>
                        <div class="flex flex-wrap gap-2">
                            <button v-for="t in assigned(form.subject).teachers" :key="t.id" type="button" :disabled="busy"
                                    class="rounded-md border px-3 py-1.5 text-sm transition-colors"
                                    :class="Number(form.teacher_id) === t.id ? 'border-brand-700 bg-brand-700 text-white' : 'border-slate-300 bg-white text-slate-700 hover:border-brand-400 hover:text-brand-800 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200'"
                                    @click="pickTeacher(t.id)">{{ t.name }}</button>
                        </div>
                    </div>
                    <label class="field-label" for="teacher">Teacher</label>
                    <select id="teacher" v-model="form.teacher_id" class="field">
                        <option value="">No teacher</option>
                        <option v-for="t in teachers" :key="t.id" :value="t.id">{{ t.name }}</option>
                    </select>
                    <p class="mt-1 text-xs text-slate-500">
                        <template v-if="assigned(form.subject)?.teacher">Assigned in the class module: <b>{{ assigned(form.subject).teacher }}</b>. Change it only for this period if someone else takes it.</template>
                        <template v-else>No teacher is assigned to this subject in the class module.</template>
                    </p>
                    <button type="button" class="btn btn-primary mt-3" :disabled="busy" @click="save">Save this period</button>
                </div>

                <div>
                    <p class="field-label">Or a period that is not a subject</p>
                    <div class="flex flex-wrap gap-2">
                        <button v-for="q in quick" :key="q" type="button" :disabled="busy" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-700 hover:border-brand-400 hover:text-brand-800 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200" @click="pickLabel(q)">{{ q }}</button>
                    </div>
                    <div class="mt-2 flex gap-2">
                        <input v-model="form.label" type="text" maxlength="60" class="field" placeholder="Something else…" aria-label="Another kind of period" @keydown.enter.prevent="form.subject = ''; save()" />
                        <button type="button" class="btn" :disabled="busy || !form.label" @click="form.subject = ''; save()">Use</button>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-between border-t border-slate-200 px-5 py-3 dark:border-slate-800">
                <button type="button" class="btn btn-danger" :disabled="busy || !cell(open.day.id, open.period.id)" @click="clearCell">Empty this period</button>
                <button type="button" class="btn" @click="closeCell">Close</button>
            </div>
        </aside>

        <!-- whole-routine dialogs -->
        <div v-if="dialog" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 sm:p-8" @mousedown.self="dialog = null">
            <div class="w-full rounded-lg bg-white shadow-xl dark:bg-slate-900" :class="['periods', 'auto'].includes(dialog) ? 'max-w-4xl' : 'max-w-md'" role="dialog" aria-modal="true">
                <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3 dark:border-slate-800">
                    <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                        {{ { periods: 'Periods and breaks of this class', auto: 'Make the routine automatically', copyDay: `Copy ${copyFromDay?.en} to other days`, copyFrom: 'Copy from another class', clear: 'Empty this routine', publish: g.published ? 'Publish changes' : 'Publish this routine', unpublish: 'Unpublish' }[dialog] }}
                    </h2>
                    <button type="button" class="text-xl leading-none text-slate-400 hover:text-slate-700" aria-label="Close" @click="dialog = null">×</button>
                </div>
                <div class="px-5 py-5 text-sm text-slate-700 dark:text-slate-300">
                    <ClassPeriodsDialog v-if="dialog === 'periods'" :class-id="g.class.id" :periods="g.periods" :source="g.period_source" :classes="classes" :errors="errors" @close="dialog = null" />
                    <AutoFillDialog v-else-if="dialog === 'auto' && plan" :class-id="g.class.id" :plan="plan" :grid="g" @close="dialog = null" />
                    <div v-else-if="dialog === 'copyDay'">
                        <p class="mb-3">The periods of {{ copyFromDay.en }} are copied onto the days you tick, replacing what is there. A period is skipped if its teacher is busy elsewhere.</p>
                        <label v-for="d in g.days.filter((x) => x.id !== copyFromDay.id)" :key="d.id" class="mr-4 inline-flex items-center gap-1.5"><input v-model="copyTo" type="checkbox" :value="d.id" />{{ d.en }}</label>
                        <div class="mt-5 flex justify-end gap-2"><button type="button" class="btn" @click="dialog = null">Cancel</button><button type="button" class="btn btn-primary" :disabled="!copyTo.length" @click="doCopyDay">Copy</button></div>
                    </div>
                    <div v-else-if="dialog === 'copyFrom'">
                        <p class="mb-3">Start this routine from another class or section. Periods are matched by position; subjects this class doesn't have are left out; each subject gets <b>this</b> class's own subject teacher. Anything already here is replaced.</p>
                        <label class="field-label" for="from">Copy from</label>
                        <select id="from" v-model="copyClass" class="field"><option value="">Choose a class</option><option v-for="c in others" :key="c.id" :value="c.id">{{ c.label }}</option></select>
                        <div class="mt-5 flex justify-end gap-2"><button type="button" class="btn" @click="dialog = null">Cancel</button><button type="button" class="btn btn-primary" :disabled="!copyClass" @click="doCopyFrom">Copy</button></div>
                    </div>
                    <div v-else-if="dialog === 'clear'">
                        <p>Remove every period from the draft of <b>{{ g.class.full_label }}</b>? A published copy, if there is one, stays as it is until you publish again.</p>
                        <div class="mt-5 flex justify-end gap-2"><button type="button" class="btn" @click="dialog = null">Cancel</button><button type="button" class="btn btn-danger" @click="doClear">Empty it</button></div>
                    </div>
                    <div v-else-if="dialog === 'publish'">
                        <p>Teachers and everyone allowed to see routines will see this version. You can keep editing afterwards; nothing changes for them until you publish again.</p>
                        <p v-if="noTeacher.length || notPlaced.length" class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-amber-900">Still open: <template v-if="notPlaced.length">not in the routine — {{ notPlaced.join(', ') }}. </template><template v-if="noTeacher.length">no teacher — {{ noTeacher.join(', ') }}.</template></p>
                        <div class="mt-5 flex justify-end gap-2"><button type="button" class="btn" @click="dialog = null">Not yet</button><button type="button" class="btn btn-primary" @click="doPublish">{{ g.published ? 'Publish changes' : 'Publish' }}</button></div>
                    </div>
                    <div v-else-if="dialog === 'unpublish'">
                        <p>Hide this routine from teachers and everyone else? The draft is kept.</p>
                        <div class="mt-5 flex justify-end gap-2"><button type="button" class="btn" @click="dialog = null">Cancel</button><button type="button" class="btn btn-danger" @click="doUnpublish">Unpublish</button></div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
