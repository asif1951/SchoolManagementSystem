<script setup>
/**
 * The routine of one class. Everything done in the table (drag and drop, swap, place, empty) stays on this screen until
 * "Save changes" is pressed: nothing is sent to the server while arranging, so it is instant. The rules (a teacher is
 * never in two classes at once, the weekly plan) are checked here at once and again by the server when saving.
 */
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
const teaching = computed(() => g.value.periods.filter((p) => !p.is_break))
const dayName = (id) => g.value.days.find((d) => d.id === id)?.en
const periodName = (id) => g.value.periods.find((p) => p.id === id)?.name

// ---- the unsaved copy ------------------------------------------------------------------------------------------------
const local = ref(null)            // null = nothing changed; otherwise the whole table as it looks now
const history = ref([])
const cells = computed(() => local.value ?? g.value.cells)
const cell = (day, period) => cells.value[cellKey(day, period)] || null

const same = (a, b) => (!a && !b) || (!!a && !!b && a.subject === b.subject && a.is_subject === b.is_subject && (a.teacher_id ?? null) === (b.teacher_id ?? null))
const changedKeys = computed(() => {
    if (!local.value) return []
    const keys = new Set([...Object.keys(local.value), ...Object.keys(g.value.cells)])
    return [...keys].filter((k) => !same(local.value[k], g.value.cells[k]))
})
const dirty = computed(() => changedKeys.value.length > 0)
const isChanged = (day, period) => changedKeys.value.includes(cellKey(day, period))

const change = (edits) => {
    const next = { ...cells.value }
    history.value.push(cells.value)
    for (const [key, value] of Object.entries(edits)) {
        if (value) next[key] = value
        else delete next[key]
    }
    local.value = next
}
const undo = () => { const last = history.value.pop(); if (last) local.value = history.value.length ? last : null }
const discard = () => { local.value = null; history.value = []; moving.value = null; notify('success', 'The changes were discarded.') }

const saving = ref(false)
const saveAll = () => {
    if (!dirty.value || saving.value) return
    saving.value = true
    const changes = changedKeys.value.map((k) => {
        const [day, period] = k.split('-').map(Number)
        const c = local.value[k]
        return { day, period_id: period, subject: c?.is_subject ? c.subject : null, label: c && !c.is_subject ? c.subject : null, teacher_id: c?.teacher_id ?? null }
    })
    router.put(route('routine.cells', g.value.class.id), { changes }, {
        preserveScroll: true, preserveState: true,
        onSuccess: () => { local.value = null; history.value = []; notify('success', 'The routine was saved.') },
        onError: (e) => notify('error', e.save || Object.values(e)[0] || 'It could not be saved.'),
        onFinish: () => { saving.value = false },
    })
}

// do not lose unsaved work by leaving the page
const warning = 'This routine has changes that are not saved. Leave without saving?'
const beforeUnload = (e) => { if (dirty.value) { e.preventDefault(); e.returnValue = '' } }
let stopWatchingVisits = null

// ---- facts about the table --------------------------------------------------------------------------------------------
const placed = (name) => Object.values(cells.value).filter((c) => c.subject === name).length
const planRow = (name) => (props.plan?.saved ? props.plan.rows.find((r) => r.subject === name && r.periods_per_week > 0) : null)
const filled = computed(() => Object.keys(cells.value).filter((k) => { const [d, p] = k.split('-').map(Number); return g.value.days.some((x) => x.id === d) && teaching.value.some((x) => x.id === p) }).length)
const notPlaced = computed(() => g.value.subjects.filter((s) => !placed(s.name)).map((s) => s.name))
const noTeacher = computed(() => g.value.subjects.filter((s) => !s.teacher_id).map((s) => s.name))

const busyFor = (day, period, teacherId) => (teacherId ? g.value.busy?.[cellKey(day, period)]?.[teacherId] : null) || null

// would these edits break a rule? returns the sentence to show, or null
const refusal = (edits) => {
    for (const [key, c] of Object.entries(edits)) {
        const [day, period] = key.split('-').map(Number)
        const where = c ? busyFor(day, period, c.teacher_id) : null
        if (where) return `${c.teacher} is already teaching ${where}. A teacher cannot be in two classes at the same time.`
    }
    const next = { ...cells.value }
    for (const [key, c] of Object.entries(edits)) { if (c) next[key] = c; else delete next[key] }
    for (const s of g.value.subjects) {
        const plan = planRow(s.name)
        if (!plan) continue
        const count = (table, day) => Object.entries(table).filter(([k, c]) => c.subject === s.name && (day === null || k.startsWith(`${day}-`))).length
        if (count(next, null) > plan.periods_per_week && count(next, null) > count(cells.value, null)) return `${s.name} already has all ${plan.periods_per_week} periods of the week in this class's plan.`
        for (const d of g.value.days) {
            if (count(next, d.id) > plan.max_per_day && count(next, d.id) > count(cells.value, d.id)) return `${s.name} is allowed ${plan.max_per_day} a day, and ${d.en} would have ${count(next, d.id)}.`
        }
    }
    return null
}

const makeCell = (subject, teacherId) => {
    const s = g.value.subjects.find((x) => x.name === subject)
    const teacher = teacherId ? (s?.teachers.find((t) => t.id === teacherId)?.name ?? props.teachers.find((t) => t.id === teacherId)?.name ?? null) : null
    return { subject, short: s?.short ?? null, is_subject: true, teacher_id: teacherId || null, teacher, all_teachers: [...new Set([teacher, ...(s?.teachers.map((t) => t.name) ?? [])].filter(Boolean))] }
}
const labelCell = (text) => ({ subject: text, short: null, is_subject: false, teacher_id: null, teacher: null, all_teachers: [] })
const freeTeacher = (s, day, period) => (s.teachers || []).find((t) => !busyFor(day, period, t.id))

// ---- messages ---------------------------------------------------------------------------------------------------------
const toast = ref(null)            // { type, text }
let toastTimer = null
const notify = (type, text) => {
    toast.value = { type, text }
    clearTimeout(toastTimer)
    toastTimer = setTimeout(() => { toast.value = null }, type === 'error' ? 9000 : 3500)
}

// ---- drag and drop ----------------------------------------------------------------------------------------------------
// Our own dragging (pointer events), so the card that follows the mouse is solid and sharp, and drops are exact.
const drag = ref(null)             // { type: 'cell'|'subject', title, sub, day?, period?, s?, x, y, width }
const moving = ref(null)           // a cell picked up with "Move or swap" (touch screens and keyboards)
const over = ref(null)             // { day, period }
const board = ref(null)
const ghost = ref(null)
let press = null
let swallowClick = false

const carried = computed(() => drag.value || moving.value)
const isSource = (day, period) => carried.value?.type === 'cell' && carried.value.day === day && carried.value.period === period
const isOver = (day, period) => over.value?.day === day && over.value?.period === period

// the edits that dropping the carried thing here would make
const editsFor = (x, day, period) => {
    if (x.type === 'subject') {
        const t = freeTeacher(x.s, day, period) || x.s.teachers[0]
        return { [cellKey(day, period)]: makeCell(x.s.name, t?.id) }
    }
    return { [cellKey(day, period)]: cell(x.day, x.period), [cellKey(x.day, x.period)]: cell(day, period) }
}
// which periods refuse what is being carried: worked out once per pick-up
const blockedMap = computed(() => {
    const x = carried.value
    const out = {}
    if (!x) return out
    for (const d of g.value.days) {
        for (const p of teaching.value) {
            if (x.type === 'cell' && x.day === d.id && x.period === p.id) continue
            const why = refusal(editsFor(x, d.id, p.id))
            if (why) out[cellKey(d.id, p.id)] = why
        }
    }
    return out
})
const whyNot = (day, period) => blockedMap.value[cellKey(day, period)] || null

const drop = (x, day, period) => {
    if (x.type === 'cell' && x.day === day && x.period === period) return
    const edits = editsFor(x, day, period)
    const why = refusal(edits)
    if (why) return notify('error', why)
    change(edits)
}

const pressCell = (e, day, period) => {
    const c = cell(day, period)
    if (c) startPress(e, { type: 'cell', day, period, title: c.subject, sub: (c.all_teachers || []).join(' / ') })
}
const pressSubject = (e, s) => startPress(e, { type: 'subject', s, title: s.name, sub: s.teacher || 'no teacher' })

const startPress = (e, item) => {
    swallowClick = false
    if (e.button !== 0 || e.pointerType === 'touch' || moving.value) return       // touch screens scroll; they use "Move or swap"
    const box = e.currentTarget.getBoundingClientRect()
    press = { item, x: e.clientX, y: e.clientY, dx: e.clientX - box.left, dy: e.clientY - box.top, width: Math.max(150, box.width) }
    window.addEventListener('pointermove', onMove)
    window.addEventListener('pointerup', onUp)
    window.addEventListener('pointercancel', cancelDrag)
}
const place = (x, y) => { if (ghost.value) ghost.value.style.transform = `translate(${Math.round(x - press.dx)}px, ${Math.round(y - press.dy)}px)` }
const targetAt = (x, y) => {
    const el = document.elementFromPoint(x, y)?.closest('[data-cell]')
    if (!el) return null
    const [day, period] = el.dataset.cell.split('-').map(Number)
    return { day, period }
}
const onMove = (e) => {
    if (!press) return
    if (!drag.value) {
        if (Math.hypot(e.clientX - press.x, e.clientY - press.y) < 5) return      // a click, not a drag
        drag.value = { ...press.item, width: press.width }
        document.body.style.userSelect = 'none'
        document.body.style.cursor = 'grabbing'
    }
    e.preventDefault()
    place(e.clientX, e.clientY)
    const t = targetAt(e.clientX, e.clientY)
    if (t?.day !== over.value?.day || t?.period !== over.value?.period) over.value = t
    // scroll when held near an edge
    const area = board.value?.getBoundingClientRect()
    if (area) board.value.scrollLeft += e.clientX > area.right - 48 ? 14 : e.clientX < area.left + 170 ? -14 : 0
    if (e.clientY > window.innerHeight - 40) window.scrollBy(0, 14)
    else if (e.clientY < 60) window.scrollBy(0, -14)
}
const cancelDrag = () => {
    press = null
    drag.value = null
    over.value = null
    document.body.style.userSelect = ''
    document.body.style.cursor = ''
    window.removeEventListener('pointermove', onMove)
    window.removeEventListener('pointerup', onUp)
    window.removeEventListener('pointercancel', cancelDrag)
}
const onUp = (e) => {
    const x = drag.value
    const t = x ? targetAt(e.clientX, e.clientY) : null
    if (x) swallowClick = true
    cancelDrag()
    if (x && t) drop(x, t.day, t.period)
}

// ---- one cell, opened by clicking ------------------------------------------------------------------------------------
const quick = ['Games', 'Library', 'Assembly', 'Moral Education', 'Computer Lab']
const open = ref(null)             // { day, period }
const form = reactive({ subject: '', label: '', teacher_id: '' })
const error = ref('')

const openCell = (day, period) => {
    if (swallowClick) { swallowClick = false; return }
    if (moving.value) {
        const x = moving.value
        moving.value = null
        return drop(x, day.id, period.id)
    }
    const c = cell(day.id, period.id)
    open.value = { day, period }
    error.value = ''
    form.subject = c?.is_subject ? c.subject : ''
    form.label = c && !c.is_subject ? c.subject : ''
    form.teacher_id = c?.teacher_id ?? ''
}
const closeCell = () => { open.value = null; error.value = '' }
const assigned = (name) => g.value.subjects.find((s) => s.name === name) || null
const busyAt = (teacherId) => (open.value ? busyFor(open.value.day.id, open.value.period.id, teacherId) : null)

const put = (value) => {
    const edits = { [cellKey(open.value.day.id, open.value.period.id)]: value }
    const why = value ? refusal(edits) : null
    if (why) { error.value = why; return }
    change(edits)
    closeCell()
}
// one teacher fills the period at once; with several, the admin picks which of them takes this period
const pickSubject = (s) => {
    const list = s.teachers || []
    const free = freeTeacher(s, open.value.day.id, open.value.period.id)
    error.value = ''
    form.subject = s.name; form.label = ''; form.teacher_id = free?.id ?? ''
    if (list.length && !free) { error.value = list.length === 1 ? `${list[0].name} cannot take this period: already teaching ${busyAt(list[0].id)}.` : `All teachers of ${s.name} are in other classes at this time.`; return }
    if (list.length < 2) put(makeCell(s.name, free?.id))
}
const pickTeacher = (id) => { form.teacher_id = id; put(makeCell(form.subject, id)) }
const useSubject = () => put(makeCell(form.subject, Number(form.teacher_id) || null))
const pickLabel = (text) => { if (text?.trim()) put(labelCell(text.trim())) }
const startMove = () => {
    const day = open.value.day.id
    const period = open.value.period.id
    moving.value = { type: 'cell', day, period, title: cell(day, period).subject }
    closeCell()
}

// ---- keys and leaving ------------------------------------------------------------------------------------------------
const onKey = (e) => {
    if (e.key === 'Escape') {
        if (drag.value) { swallowClick = true; cancelDrag() } else if (moving.value) moving.value = null; else if (dialog.value) dialog.value = null; else closeCell()
    } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); saveAll() }
    else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z' && !['INPUT', 'TEXTAREA'].includes(e.target.tagName)) { e.preventDefault(); undo() }
}
onMounted(() => {
    window.addEventListener('keydown', onKey)
    window.addEventListener('beforeunload', beforeUnload)
    stopWatchingVisits = router.on('before', (e) => {
        if (dirty.value && e.detail.visit.method === 'get' && !window.confirm(warning)) { goTo.value = String(g.value.class.id); return false }
    })
})
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKey)
    window.removeEventListener('beforeunload', beforeUnload)
    stopWatchingVisits?.()
    clearTimeout(toastTimer)
    cancelDrag()
})

// ---- the class switcher ----------------------------------------------------------------------------------------------
const goTo = ref(String(g.value.class.id))
watch(goTo, (id) => { if (String(id) !== String(g.value.class.id)) router.visit(route('routine.editor', id)) })
watch(() => g.value.class.id, (id) => { local.value = null; history.value = []; moving.value = null; open.value = null; goTo.value = String(id) })

// ---- whole-routine actions (they work on the saved routine, so changes are saved first) -------------------------------
const dialog = ref(null)           // periods | auto | copyDay | copyFrom | clear | publish | unpublish
const copyFromDay = ref(null)
const copyTo = ref([])
const copyClass = ref('')
const pageError = ref('')

const askCopyDay = (day) => { copyFromDay.value = day; copyTo.value = []; dialog.value = 'copyDay' }
const doCopyDay = () => router.post(route('routine.copy-day', g.value.class.id), { from_day: copyFromDay.value.id, to_days: copyTo.value }, { preserveScroll: true, onSuccess: () => { dialog.value = null } })
const doCopyFrom = () => router.post(route('routine.copy-from', g.value.class.id), { from_class_id: copyClass.value }, { preserveScroll: true, onSuccess: () => { dialog.value = null } })
const doClear = () => router.delete(route('routine.clear', g.value.class.id), { preserveScroll: true, onSuccess: () => { dialog.value = null } })
const doPublish = () => { pageError.value = ''; router.post(route('routine.publish', g.value.class.id), {}, { preserveScroll: true, onSuccess: () => { dialog.value = null }, onError: (e) => { pageError.value = e.publish; dialog.value = null; window.scrollTo({ top: 0, behavior: 'smooth' }) } }) }
const doUnpublish = () => router.delete(route('routine.unpublish', g.value.class.id), { preserveScroll: true, onSuccess: () => { dialog.value = null } })

const periodSourceText = computed(() => ({ class: 'its own periods', shift: 'the shift periods', common: 'the common periods' }[g.value.period_source]))
</script>

<template>
    <Head :title="`Routine — ${g.class.full_label}`" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-slate-50 text-slate-800 dark:bg-slate-950 dark:text-slate-200" :class="dirty ? 'pb-24' : ''">
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
                    <div class="w-56">
                        <label class="field-label" for="goto">Go to another class</label>
                        <select id="goto" v-model="goTo" class="field"><option v-for="c in classes" :key="c.id" :value="String(c.id)">{{ c.label }}</option></select>
                    </div>
                </header>

                <!-- state of the routine and what can be done with it -->
                <section class="panel mb-4 flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                    <div class="flex flex-wrap items-center gap-3 text-sm">
                        <span class="badge" :class="g.published ? (g.changed ? 'border-brand-200 bg-brand-50 text-brand-800' : 'border-green-200 bg-green-50 text-green-800') : 'border-amber-200 bg-amber-50 text-amber-800'">
                            {{ g.published ? (g.changed ? 'Published · changes waiting' : 'Published') : 'Draft' }}
                        </span>
                        <span class="text-slate-500">{{ filled }} of {{ g.total }} periods filled</span>
                        <span class="text-slate-400">· {{ periodSourceText }}</span>
                        <span v-if="g.published" class="text-slate-400">published {{ g.published_at }}</span>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span v-if="dirty" class="text-xs text-amber-700">Save your changes to use these</span>
                        <button type="button" class="btn" :disabled="dirty" @click="dialog = 'periods'">Periods &amp; breaks</button>
                        <button type="button" class="btn" :disabled="dirty" @click="dialog = 'auto'">Make it automatically</button>
                        <button type="button" class="btn" :disabled="dirty" @click="copyClass = ''; dialog = 'copyFrom'">Copy from another class</button>
                        <button type="button" class="btn" :disabled="dirty || !g.filled" @click="dialog = 'clear'">Empty it</button>
                        <a v-if="!dirty" :href="route('routine.print', { class: g.class.id, source: 'draft' })" target="_blank" rel="noopener" class="btn">Print</a>
                        <button v-if="g.published" type="button" class="btn" :disabled="dirty" @click="dialog = 'unpublish'">Unpublish</button>
                        <button type="button" class="btn btn-primary" :disabled="dirty || !g.filled || (g.published && !g.changed)" @click="dialog = 'publish'">{{ g.published ? 'Publish changes' : 'Publish' }}</button>
                    </div>
                </section>

                <div v-if="flash.success" class="mb-3 rounded-md border border-l-4 border-slate-200 border-l-green-600 bg-white px-4 py-3 text-sm dark:border-slate-700 dark:bg-slate-900" role="status">{{ flash.success }}</div>
                <div v-if="flash.error" class="mb-3 rounded-md border border-l-4 border-slate-200 border-l-amber-500 bg-white px-4 py-3 text-sm dark:border-slate-700 dark:bg-slate-900" role="alert">{{ flash.error }}</div>
                <div v-if="pageError" class="mb-3 rounded-md border border-l-4 border-slate-200 border-l-red-600 bg-white px-4 py-3 text-sm dark:border-slate-700 dark:bg-slate-900" role="alert">{{ pageError }}</div>

                <p v-if="!teaching.length" class="panel px-4 py-10 text-center text-sm text-slate-500">
                    There are no periods for this shift yet. <Link :href="route('routine.periods')" class="font-medium text-brand-700 hover:underline">Set the periods first.</Link>
                </p>

                <!-- the routine: days down the side, periods across -->
                <section v-else class="panel overflow-hidden">
                    <div class="border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                        <p class="text-sm text-slate-600 dark:text-slate-300"><b>Drag</b> a subject onto a period, or one period onto another to swap them. Then press <b>Save changes</b>.</p>
                        <p class="mt-0.5 text-xs text-slate-500">Touch screen or keyboard: open a period and choose “Move or swap”.</p>
                        <div v-if="g.subjects.length" class="mt-2.5 flex flex-wrap gap-2">
                            <span v-for="s in g.subjects" :key="s.name" class="inline-flex cursor-grab select-none items-center gap-2 rounded-md border border-slate-300 bg-white py-1.5 pl-2 pr-2.5 text-sm font-medium text-slate-800 shadow-sm transition-colors hover:border-brand-500 hover:bg-brand-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100" @pointerdown="pressSubject($event, s)">
                                <span class="text-slate-400" aria-hidden="true">⠿</span>{{ s.name }}
                                <span class="rounded bg-slate-100 px-1.5 text-xs tabular-nums text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ placed(s.name) }}<template v-if="planRow(s.name)">/{{ planRow(s.name).periods_per_week }}</template></span>
                            </span>
                        </div>
                    </div>
                    <div v-if="moving" class="flex flex-wrap items-center justify-between gap-2 border-b border-brand-200 bg-brand-50 px-4 py-2 text-sm text-brand-900" role="status">
                        <span>Moving <b>{{ moving.title }}</b> ({{ dayName(moving.day) }}, {{ periodName(moving.period) }}). Choose the period to swap with.</span>
                        <button type="button" class="btn" @click="moving = null">Cancel</button>
                    </div>

                    <div ref="board" class="overflow-x-auto">
                        <table class="plain w-full table-fixed border-collapse text-sm" :style="{ minWidth: 128 + teaching.length * 124 + (g.periods.length - teaching.length) * 56 + 'px' }">
                            <colgroup>
                                <col style="width: 128px" />
                                <col v-for="p in g.periods" :key="p.id" :style="p.is_break ? 'width: 56px' : ''" />
                            </colgroup>
                            <thead>
                                <tr class="bg-slate-50 dark:bg-slate-900">
                                    <th class="sticky left-0 z-10 border-b border-r border-slate-200 bg-slate-50 px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:bg-slate-900">Day</th>
                                    <th v-for="p in g.periods" :key="p.id" class="border-b border-slate-200 px-1 py-2 text-center dark:border-slate-800" :class="p.is_break ? 'bg-slate-100 dark:bg-slate-800/60' : ''">
                                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300">{{ p.name }}</div>
                                        <div v-if="!p.is_break" class="mt-0.5 whitespace-nowrap text-[11px] font-normal normal-case tracking-normal text-slate-500">{{ p.time }}</div>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="d in g.days" :key="d.id" class="border-b border-slate-200 last:border-0 dark:border-slate-800">
                                    <th class="sticky left-0 z-10 border-r border-slate-200 bg-white px-3 py-2 text-left align-top dark:border-slate-800 dark:bg-slate-900">
                                        <div class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ d.en }}</div>
                                        <button type="button" class="mt-1 text-[11px] font-medium normal-case text-brand-700 hover:underline disabled:text-slate-400 disabled:no-underline" :disabled="dirty" @click="askCopyDay(d)">Copy to other days</button>
                                    </th>
                                    <template v-for="p in g.periods" :key="p.id">
                                        <td v-if="p.is_break" class="bg-slate-100 text-center text-[11px] font-medium uppercase tracking-wide text-slate-400 dark:bg-slate-800/60">Break</td>
                                        <td v-else class="p-1 align-top">
                                            <button type="button" :data-cell="cellKey(d.id, p.id)"
                                                    class="relative flex h-20 w-full select-none flex-col items-start justify-center overflow-hidden rounded-md border-2 px-2 py-1.5 text-left transition-colors duration-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-brand-700"
                                                    :class="isSource(d.id, p.id) ? 'border-dashed border-brand-600 bg-brand-50'
                                                        : carried && isOver(d.id, p.id) ? (whyNot(d.id, p.id) ? 'border-red-600 bg-red-100' : 'border-brand-700 bg-brand-100')
                                                        : whyNot(d.id, p.id) ? 'border-red-200 bg-red-50'
                                                        : carried ? 'border-dashed border-brand-300 bg-white dark:bg-slate-900'
                                                        : isChanged(d.id, p.id) ? 'border-amber-400 bg-amber-50 dark:bg-amber-950/30'
                                                        : cell(d.id, p.id) ? 'cursor-grab border-slate-200 bg-white hover:border-brand-500 dark:border-slate-700 dark:bg-slate-900'
                                                        : 'border-dashed border-slate-200 text-slate-300 hover:border-brand-500 hover:bg-brand-50 hover:text-brand-700 dark:border-slate-700'"
                                                    :title="whyNot(d.id, p.id) || ''"
                                                    :aria-label="`${d.en}, ${p.name} period${cell(d.id, p.id) ? ': ' + cell(d.id, p.id).subject : ', empty'}${isChanged(d.id, p.id) ? ', not saved' : ''}${whyNot(d.id, p.id) ? '. Not allowed: ' + whyNot(d.id, p.id) : ''}`"
                                                    @pointerdown="pressCell($event, d.id, p.id)" @click="openCell(d, p)">
                                                <span v-if="isChanged(d.id, p.id)" class="absolute right-1.5 top-1.5 h-2 w-2 rounded-full bg-amber-500" aria-hidden="true"></span>
                                                <span v-if="whyNot(d.id, p.id) && isOver(d.id, p.id)" class="w-full text-center text-xs font-semibold text-red-800">Not allowed</span>
                                                <template v-else-if="cell(d.id, p.id)">
                                                    <span class="line-clamp-2 w-full pr-2 text-[13px] font-semibold leading-tight text-slate-900 dark:text-white">{{ cell(d.id, p.id).subject }}</span>
                                                    <span class="mt-0.5 w-full truncate text-xs text-slate-600 dark:text-slate-400">{{ (cell(d.id, p.id).all_teachers || []).join(' / ') || (cell(d.id, p.id).is_subject ? 'no teacher' : '') }}</span>
                                                </template>
                                                <span v-else class="w-full text-center text-xl leading-none">+</span>
                                                <span v-if="whyNot(d.id, p.id) && !isOver(d.id, p.id)" class="text-[10px] font-semibold uppercase tracking-wide text-red-700">Not allowed</span>
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
                                    <td class="num">{{ placed(s.name) }}</td>
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
                            <template v-if="!dirty">
                                <li v-for="x in (g.plan_check?.over || [])" :key="x" class="text-red-700"><b>Against the plan:</b> {{ x }}</li>
                                <li v-if="(g.plan_check?.under || []).length" class="text-amber-800"><b>Still to place:</b> {{ g.plan_check.under.join(', ') }}</li>
                            </template>
                            <li v-if="!noTeacher.length && !notPlaced.length && !(g.plan_check?.over || []).length" class="text-green-700">Every subject has a teacher and a place in the week.</li>
                        </ul>
                    </section>
                </div>
            </div>
        </div>

        <!-- the card that follows the mouse: solid and sharp -->
        <div v-show="drag" ref="ghost" class="pointer-events-none fixed left-0 top-0 z-[70] rounded-md border-2 border-brand-900 bg-brand-700 px-3 py-2 text-white shadow-lg" :style="{ width: (drag?.width || 150) + 'px', willChange: 'transform' }" aria-hidden="true">
            <template v-if="drag">
                <div class="truncate text-sm font-semibold">{{ drag.title }}</div>
                <div class="truncate text-xs text-white">{{ drag.sub }}</div>
            </template>
        </div>

        <!-- unsaved changes -->
        <div v-if="dirty" class="fixed inset-x-0 bottom-0 z-30 border-t-2 border-amber-400 bg-white px-4 py-3 shadow-[0_-4px_16px_rgba(0,0,0,0.08)] dark:bg-slate-900" role="region" aria-label="Unsaved changes">
            <div class="mx-auto flex max-w-[1400px] flex-wrap items-center justify-between gap-3">
                <p class="text-sm font-semibold text-slate-900 dark:text-white" aria-live="polite">{{ changedKeys.length }} {{ changedKeys.length === 1 ? 'period' : 'periods' }} changed, not saved yet</p>
                <div class="flex gap-2">
                    <button type="button" class="btn" :disabled="saving || !history.length" title="Ctrl+Z" @click="undo">Undo</button>
                    <button type="button" class="btn" :disabled="saving" @click="discard">Discard</button>
                    <button type="button" class="btn btn-primary min-w-[9rem]" :disabled="saving" title="Ctrl+S" @click="saveAll">{{ saving ? 'Saving…' : 'Save changes' }}</button>
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
                <p v-if="error" class="rounded-md border-2 border-red-300 bg-red-50 px-3 py-2.5 text-sm font-medium text-red-800" role="alert">{{ error }}</p>

                <div>
                    <p class="field-label">Subject — click one to fill the period</p>
                    <div class="flex flex-wrap gap-2">
                        <button v-for="s in g.subjects" :key="s.name" type="button"
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
                            <button v-for="t in assigned(form.subject).teachers" :key="t.id" type="button" :disabled="!!busyAt(t.id)"
                                    class="rounded-md border px-3 py-1.5 text-left text-sm transition-colors disabled:cursor-not-allowed disabled:border-red-200 disabled:bg-red-50 disabled:text-red-700"
                                    :class="Number(form.teacher_id) === t.id ? 'border-brand-700 bg-brand-700 text-white' : 'border-slate-300 bg-white text-slate-700 hover:border-brand-400 hover:text-brand-800 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200'"
                                    @click="pickTeacher(t.id)">{{ t.name }}<span v-if="busyAt(t.id)" class="block text-xs">Busy: {{ busyAt(t.id) }}</span></button>
                        </div>
                    </div>
                    <label class="field-label" for="teacher">Teacher</label>
                    <select id="teacher" v-model="form.teacher_id" class="field">
                        <option value="">No teacher</option>
                        <option v-for="t in teachers" :key="t.id" :value="t.id" :disabled="!!busyAt(t.id)">{{ t.name }}{{ busyAt(t.id) ? ` — busy: ${busyAt(t.id)}` : '' }}</option>
                    </select>
                    <p class="mt-1 text-xs text-slate-500">
                        <template v-if="assigned(form.subject)?.teacher">Assigned in the class module: <b>{{ assigned(form.subject).teacher }}</b>. Change it only for this period if someone else takes it.</template>
                        <template v-else>No teacher is assigned to this subject in the class module.</template>
                    </p>
                    <button type="button" class="btn btn-primary mt-3" :disabled="!!busyAt(Number(form.teacher_id))" @click="useSubject">Use for this period</button>
                </div>

                <div>
                    <p class="field-label">Or a period that is not a subject</p>
                    <div class="flex flex-wrap gap-2">
                        <button v-for="q in quick" :key="q" type="button" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-700 hover:border-brand-400 hover:text-brand-800 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200" @click="pickLabel(q)">{{ q }}</button>
                    </div>
                    <div class="mt-2 flex gap-2">
                        <input v-model="form.label" type="text" maxlength="60" class="field" placeholder="Something else…" aria-label="Another kind of period" @keydown.enter.prevent="pickLabel(form.label)" />
                        <button type="button" class="btn" :disabled="!form.label" @click="pickLabel(form.label)">Use</button>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-between border-t border-slate-200 px-5 py-3 dark:border-slate-800">
                <div class="flex gap-2">
                    <button type="button" class="btn" :disabled="!cell(open.day.id, open.period.id)" @click="startMove">Move or swap</button>
                    <button type="button" class="btn btn-danger" :disabled="!cell(open.day.id, open.period.id)" @click="put(null)">Empty this period</button>
                </div>
                <button type="button" class="btn" @click="closeCell">Close</button>
            </div>
        </aside>

        <div v-if="toast" class="fixed right-4 z-[60] flex max-w-sm items-start gap-3 rounded-md border border-l-4 border-slate-200 bg-white px-4 py-3 text-sm shadow-lg dark:border-slate-700 dark:bg-slate-900"
             :class="[toast.type === 'error' ? 'border-l-red-600' : 'border-l-green-600', dirty ? 'bottom-20' : 'bottom-4']" :role="toast.type === 'error' ? 'alert' : 'status'">
            <span>{{ toast.text }}</span>
            <button type="button" class="text-slate-400 hover:text-slate-700" aria-label="Dismiss" @click="toast = null">×</button>
        </div>

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
