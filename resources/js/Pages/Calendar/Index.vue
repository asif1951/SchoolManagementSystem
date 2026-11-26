<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import Dialog from '@/Components/Teaching/Dialog.vue'
import DateField from '@/Components/Calendar/DateField.vue'
import EventDialog from '@/Components/Calendar/EventDialog.vue'
import BulkDialog from '@/Components/Calendar/BulkDialog.vue'
import { Head, router } from '@inertiajs/vue3'
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { MONTHS, toBangla, digits } from '@/lib/bangla'
import { COLORS, DOTS, WEEKDAYS, WEEK_ORDER, addDays, dayNumber, diffDays, english, label, range, weekday } from '@/lib/calendar'

const props = defineProps({
    view: String, date: String, today: String, from: String, to: String, events: Array, closed: Array, filters: Object, types: Array, optionalOff: Array,
    classes: Array, weekdays: Array, stats: Object, upcoming: Array, nextHoliday: Object, tentative: Array, exams: Array, can: Object, flash: Object,
})

// ---- English, Bangla or both: remembered by this browser -------------------------------------------------------------
const stored = () => { try { return localStorage.getItem('calendar.mode') } catch { return null } }
const mode = ref(['english', 'bangla', 'both'].includes(stored()) ? stored() : 'both')
watch(mode, (m) => { try { localStorage.setItem('calendar.mode', m) } catch { /* private window */ } })

// ---- moving around ---------------------------------------------------------------------------------------------------
const query = (more = {}) => {
    const q = { view: props.view, date: props.date, types: props.filters.types, class: props.filters.class ?? undefined, ...more }
    if (!q.types?.length) delete q.types
    return q
}
const go = (more) => router.get(route('calendar.index'), query(more), { preserveScroll: true, preserveState: true })
const step = (by) => {
    if (props.view === 'week') return go({ date: addDays(props.date, 7 * by) })
    const d = new Date(`${props.date.slice(0, 7)}-01T00:00:00Z`)
    if (props.view === 'month') d.setUTCMonth(d.getUTCMonth() + by); else d.setUTCFullYear(d.getUTCFullYear() + by)
    go({ date: d.toISOString().slice(0, 10) })
}
const banglaMonthsOf = (first, last) => {
    const a = toBangla(first); const b = toBangla(last)
    return a.month === b.month ? `${MONTHS[a.month - 1]} ${digits(a.year)}` : `${MONTHS[a.month - 1]} – ${MONTHS[b.month - 1]} ${digits(b.year)}`
}
const heading = computed(() => {
    const y = props.date.slice(0, 4)
    if (props.view === 'week') return mode.value === 'bangla' ? `${label(props.from, 'bangla')} – ${label(props.to, 'bangla')}` : `${english(props.from, { day: 'numeric', month: 'short' })} – ${english(props.to)}`
    if (props.view !== 'month') return mode.value === 'bangla' ? banglaMonthsOf(`${y}-01-01`, `${y}-12-31`).replace(/^.* (?=\S+$)/, `${digits(toBangla(`${y}-01-01`).year)} – `) : y
    const first = `${props.date.slice(0, 7)}-01`
    const last = addDays(addDays(first, 32).slice(0, 7) + '-01', -1)
    return mode.value === 'bangla' ? banglaMonthsOf(first, last) : english(first, { month: 'long', year: 'numeric' })
})
const subheading = computed(() => {
    if (mode.value !== 'both' || props.view !== 'month') return ''
    const first = `${props.date.slice(0, 7)}-01`
    return banglaMonthsOf(first, addDays(addDays(first, 32).slice(0, 7) + '-01', -1))
})
const toggleType = (k) => { const t = props.filters.types.includes(k) ? props.filters.types.filter((x) => x !== k) : [...props.filters.types, k]; go({ types: t.length ? t : undefined }) }

// ---- what is on each day ---------------------------------------------------------------------------------------------
const color = (e) => props.types.find((x) => x.key === e.type)?.color ?? 'grey'
const typeLabel = (k) => props.types.find((x) => x.key === k)?.label
const days = computed(() => { const out = []; for (let d = props.from; d <= props.to; d = addDays(d, 1)) out.push(d); return out })
const byDay = computed(() => {
    const map = {}
    for (const e of props.events) {
        if (e.type === 'term') continue                    // a term spans months: shown in the list and the side panel
        for (let d = e.starts_on > props.from ? e.starts_on : props.from; d <= e.ends_on && d <= props.to; d = addDays(d, 1)) (map[d] ||= []).push(e)
    }
    return map
})
const closedSet = computed(() => new Set(props.closed))
const month = computed(() => props.date.slice(0, 7))
const yearMonths = computed(() => Array.from({ length: 12 }, (_, i) => {
    const first = `${props.date.slice(0, 4)}-${String(i + 1).padStart(2, '0')}-01`
    const blank = (weekday(first) + 1) % 7
    const list = []
    for (let d = first; d.slice(0, 7) === first.slice(0, 7); d = addDays(d, 1)) list.push(d)
    return { first, blank, list, name: mode.value === 'bangla' ? banglaMonthsOf(first, list[list.length - 1]) : english(first, { month: 'long' }), sub: mode.value === 'both' ? banglaMonthsOf(first, list[list.length - 1]) : '' }
}))

// ---- dialogs ---------------------------------------------------------------------------------------------------------
const dialog = ref(null)           // { kind, ... }
const add = (start, end = start) => { if (props.can.manage || props.can.close) dialog.value = { kind: 'event', start: start <= end ? start : end, end: start <= end ? end : start } }
const open = (e) => { dialog.value = { kind: 'event', event: e } }

// ---- drag across days to add; drag an event to move it ---------------------------------------------------------------
const select = ref(null)           // { a, b }
const moving = ref(null)           // { event, grabbed, over, started }
let swallow = false
const dayAt = (ev) => document.elementFromPoint(ev.clientX, ev.clientY)?.closest('[data-day]')?.dataset.day
const mayMove = (e) => props.can.manage || (props.can.close && ['closure', 'makeup'].includes(e.type))
const onMove = (ev) => {
    const d = dayAt(ev)
    if (!d) return
    if (select.value) select.value = { ...select.value, b: d }
    if (moving.value && d !== moving.value.over) moving.value = { ...moving.value, over: d, started: moving.value.started || d !== moving.value.grabbed }
}
const stopListening = () => { window.removeEventListener('pointermove', onMove); window.removeEventListener('pointerup', onUp); window.removeEventListener('pointercancel', cancel); document.body.style.userSelect = '' }
const cancel = () => { select.value = null; moving.value = null; stopListening() }
const onUp = () => {
    const s = select.value; const m = moving.value
    cancel()
    if (s) add(s.a, s.b)
    if (m?.started) {
        swallow = true
        const by = diffDays(m.grabbed, m.over)
        if (by !== 0) dialog.value = { kind: 'move', event: m.event, to: addDays(m.event.starts_on, by) }
    }
}
const listen = () => { document.body.style.userSelect = 'none'; window.addEventListener('pointermove', onMove); window.addEventListener('pointerup', onUp); window.addEventListener('pointercancel', cancel) }
// a finger scrolls the page: on touch screens a tap adds, and moving is done by editing the dates
const pressDay = (ev, d) => { if (ev.button !== 0 || ev.pointerType === 'touch' || !(props.can.manage || props.can.close)) return; ev.preventDefault(); select.value = { a: d, b: d }; listen() }
const tapDay = (ev, d) => { if (ev.pointerType === 'touch' || ev.detail === 0) add(d) }
const pressEvent = (ev, e, d) => { swallow = false; if (ev.button !== 0 || ev.pointerType === 'touch' || !mayMove(e)) return; moving.value = { event: e, grabbed: d, over: d, started: false }; listen() }
const clickEvent = (e) => { if (swallow) { swallow = false; return } open(e) }
const inSelection = (d) => select.value && d >= (select.value.a < select.value.b ? select.value.a : select.value.b) && d <= (select.value.a < select.value.b ? select.value.b : select.value.a)
const isTarget = (d) => moving.value?.started && moving.value.over === d
onBeforeUnmount(stopListening)

const doMove = () => router.post(route('calendar.events.move', dialog.value.event.id), { starts_on: dialog.value.to, occurrence: dialog.value.event.starts_on }, { preserveScroll: true, preserveState: true, onFinish: () => { dialog.value = null } })

// ---- tentative dates -------------------------------------------------------------------------------------------------
const settle = reactive({ starts_on: '', ends_on: '' })
const openConfirm = (t) => { Object.assign(settle, { starts_on: t.starts_on, ends_on: t.ends_on }); dialog.value = { kind: 'confirm', event: t } }
watch(() => settle.starts_on, (now, before) => { if (dialog.value?.kind === 'confirm' && before && now) settle.ends_on = addDays(now, Math.max(0, diffDays(before, settle.ends_on))) })
const doConfirm = () => router.post(route('calendar.events.confirm', dialog.value.event.id), { ...settle }, { preserveScroll: true, onSuccess: () => { dialog.value = null } })

// ---- copy last year, closure, weekly off days ------------------------------------------------------------------------
const copy = reactive({ from: Number(props.date.slice(0, 4)) - 1, list: null, ids: [], tentative: true, error: '' })
const loadCopy = async () => {
    copy.list = null; copy.error = ''
    try { copy.list = (await window.axios.post(route('calendar.copy.preview'), { from: copy.from })).data.events; copy.ids = copy.list.filter((x) => !x.skip).map((x) => x.id) }
    catch { copy.error = 'The list could not be loaded.' }
}
const openCopy = () => { copy.from = Number(props.date.slice(0, 4)) - 1; dialog.value = { kind: 'copy' }; loadCopy() }
const doCopy = () => router.post(route('calendar.copy.store'), { from: copy.from, ids: copy.ids, tentative: copy.tentative }, { preserveScroll: true, onSuccess: () => { dialog.value = null } })

const closure = reactive({ reason: '', reason_bn: '', starts_on: '', ends_on: '', audience: 'all', class_ids: [], makeup_on: '', follows_day: null, reschedule: false, errors: {} })
const openClosure = () => { Object.assign(closure, { reason: '', reason_bn: '', starts_on: props.today, ends_on: props.today, audience: 'all', class_ids: [], makeup_on: '', follows_day: null, reschedule: false, errors: {} }); dialog.value = { kind: 'closure' } }
const doClosure = () => {
    const { errors, ...data } = closure
    router.post(route('calendar.closure'), { ...data, makeup_on: data.makeup_on || null }, { preserveScroll: true, onSuccess: () => { dialog.value = null }, onError: (e) => { closure.errors = e } })
}

const off = ref([])
const openOff = () => { off.value = [0, 1, 2, 3, 4, 5, 6].filter((d) => !props.weekdays.includes(d)); dialog.value = { kind: 'off' } }
const doOff = () => router.put(route('calendar.off-days'), { off: off.value }, { preserveScroll: true, onSuccess: () => { dialog.value = null } })

const printUrl = computed(() => route('calendar.print', { year: props.date.slice(0, 4), calendar: mode.value }))
const num = (n) => (mode.value === 'bangla' ? digits(n) : n)
</script>

<template>
    <Head title="Academic calendar" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-slate-50 text-slate-800 dark:bg-slate-950 dark:text-slate-200">
            <div class="mx-auto max-w-[1500px] px-3 py-4 sm:px-6 lg:py-6">
                <header class="mb-4 flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 pb-3 dark:border-slate-800">
                    <div>
                        <h1 class="text-xl font-semibold text-slate-900 dark:text-white">Academic calendar</h1>
                        <p class="mt-0.5 text-sm text-slate-500">Every date of the school year. Routine, attendance, teaching plans and exams follow it.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button v-if="can.manage" type="button" class="btn btn-primary" @click="add(today)">+ Add a date</button>
                        <button v-if="can.manage" type="button" class="btn" @click="dialog = { kind: 'bulk' }">Add many</button>
                        <button v-if="can.manage" type="button" class="btn" @click="openCopy">Copy last year</button>
                        <button v-if="can.close" type="button" class="btn !border-red-300 !text-red-700" @click="openClosure">Emergency closure</button>
                        <button v-if="can.manage" type="button" class="btn" @click="openOff">Weekly off days</button>
                        <a :href="printUrl" target="_blank" rel="noopener" class="btn">Print year</a>
                    </div>
                </header>

                <div v-if="flash.success" class="mb-3 rounded-md border border-l-4 border-slate-200 border-l-green-600 bg-white px-4 py-2.5 text-sm dark:border-slate-700 dark:bg-slate-900" role="status">{{ flash.success }}</div>
                <div v-if="flash.error" class="mb-3 rounded-md border border-l-4 border-slate-200 border-l-red-600 bg-white px-4 py-2.5 text-sm dark:border-slate-700 dark:bg-slate-900" role="alert">{{ flash.error }}</div>

                <section class="panel mb-3 flex flex-wrap items-center gap-3 p-3">
                    <div class="flex gap-1" role="tablist" aria-label="View">
                        <button v-for="v in ['month', 'week', 'year', 'list']" :key="v" type="button" role="tab" :aria-selected="view === v" class="rounded px-3 py-1.5 text-sm font-medium capitalize" :class="view === v ? 'bg-brand-700 text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'" @click="go({ view: v })">{{ v }}</button>
                    </div>
                    <div class="flex items-center gap-1">
                        <button type="button" class="btn" aria-label="Earlier" @click="step(-1)">←</button>
                        <button type="button" class="btn" @click="go({ date: today })">Today</button>
                        <button type="button" class="btn" aria-label="Later" @click="step(1)">→</button>
                    </div>
                    <div class="min-w-[11rem]"><div class="text-base font-semibold">{{ heading }}</div><div v-if="subheading" class="text-xs text-slate-500" lang="bn">{{ subheading }}</div></div>
                    <input type="date" class="field !w-40" :value="date" aria-label="Go to a date" @change="$event.target.value && go({ date: $event.target.value })" />
                    <span class="flex-1"></span>
                    <select class="field !w-48" :value="filters.class ?? ''" aria-label="Class" @change="go({ class: $event.target.value || undefined })"><option value="">All classes</option><option v-for="c in classes" :key="c.id" :value="c.id">{{ c.label }}</option></select>
                    <div class="flex gap-1" role="group" aria-label="Calendar">
                        <button v-for="m in [['english', 'English'], ['bangla', 'বাংলা'], ['both', 'Both']]" :key="m[0]" type="button" class="rounded border px-2.5 py-1.5 text-sm" :class="mode === m[0] ? 'border-brand-700 bg-brand-700 text-white' : 'border-slate-300 dark:border-slate-700'" :aria-pressed="mode === m[0]" @click="mode = m[0]">{{ m[1] }}</button>
                    </div>
                </section>

                <div class="mb-3 flex flex-wrap gap-1.5" role="group" aria-label="Show these types">
                    <button v-for="t in types" :key="t.key" type="button" class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs"
                            :class="filters.types.includes(t.key) ? 'border-brand-700 bg-brand-50 font-semibold text-brand-900' : 'border-slate-300 bg-white text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300'" :aria-pressed="filters.types.includes(t.key)" @click="toggleType(t.key)">
                        <span class="h-2.5 w-2.5 rounded-full" :class="DOTS[t.color]"></span>{{ t.label }}</button>
                    <button v-if="filters.types.length" type="button" class="text-xs font-medium text-brand-700 underline" @click="go({ types: undefined })">Show all</button>
                </div>

                <div class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_20rem]">
                    <div class="min-w-0">
                        <!-- month and week -->
                        <section v-if="view === 'month' || view === 'week'" class="panel overflow-x-auto">
                            <div class="grid grid-cols-7" :class="view === 'week' ? 'min-w-[760px]' : 'min-w-[640px]'">
                                <div v-for="d in WEEK_ORDER" :key="d" class="border-b border-slate-200 px-2 py-1.5 text-center text-xs font-semibold uppercase tracking-wide dark:border-slate-800" :class="weekdays.includes(d) ? 'text-slate-500' : 'bg-slate-100 text-slate-400 dark:bg-slate-800'">{{ WEEKDAYS[d].slice(0, 3) }}</div>
                                <div v-for="d in days" :key="d" :data-day="d" class="group relative border-b border-r border-slate-100 p-1 dark:border-slate-800"
                                     :class="[view === 'week' ? 'min-h-[18rem]' : 'min-h-[6.5rem]', closedSet.has(d) ? 'bg-slate-100 dark:bg-slate-800/60' : 'bg-white dark:bg-slate-900', view === 'month' && d.slice(0, 7) !== month ? 'opacity-45' : '', inSelection(d) ? '!bg-brand-100 dark:!bg-brand-900/50' : '', isTarget(d) ? 'ring-2 ring-inset ring-brand-700' : '']"
                                     @pointerdown="pressDay($event, d)">
                                    <button type="button" class="flex w-full items-baseline justify-between gap-1 rounded px-0.5 text-left focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-700" :aria-label="`${label(d, 'both')}${closedSet.has(d) ? ', no school' : ''}${byDay[d] ? ', ' + byDay[d].map((x) => x.title).join(', ') : ''}. Add a date`" @click="tapDay($event, d)">
                                        <span class="text-sm font-semibold" :class="d === today ? 'rounded-full bg-brand-700 px-1.5 text-white' : ''">{{ dayNumber(d, mode) }}</span>
                                        <span v-if="mode === 'both'" class="text-xs text-slate-400" lang="bn">{{ dayNumber(d, 'bangla') }}</span>
                                    </button>
                                    <ul class="mt-1 space-y-1">
                                        <li v-for="e in (byDay[d] || []).slice(0, view === 'week' ? 30 : 3)" :key="`${e.id}-${e.starts_on}`">
                                            <button type="button" class="block w-full truncate rounded border px-1.5 py-0.5 text-left text-xs" :class="[COLORS[color(e)], e.tentative ? 'border-dashed' : '', mayMove(e) ? 'cursor-grab' : '', view === 'week' ? '!whitespace-normal' : '']"
                                                    :title="`${e.title}${e.tentative ? ' (tentative)' : ''}`" @pointerdown.stop="pressEvent($event, e, d)" @click.stop="clickEvent(e)">
                                                {{ mode === 'bangla' && e.title_bn ? e.title_bn : e.title }}{{ e.tentative ? ' ?' : '' }}</button>
                                        </li>
                                        <li v-if="view === 'month' && (byDay[d] || []).length > 3"><button type="button" class="text-xs font-medium text-brand-700 underline" @pointerdown.stop @click.stop="go({ view: 'week', date: d })">+{{ byDay[d].length - 3 }} more</button></li>
                                    </ul>
                                </div>
                            </div>
                            <p v-if="can.manage" class="px-3 py-2 text-xs text-slate-500">Click a day, or drag across several, to add a date. Drag an event to move it.</p>
                        </section>

                        <!-- year -->
                        <section v-else-if="view === 'year'" class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            <div v-for="m in yearMonths" :key="m.first" class="panel p-2">
                                <button type="button" class="mb-1 w-full text-center text-sm font-semibold hover:text-brand-700" @click="go({ view: 'month', date: m.first })">{{ m.name }}<span v-if="m.sub" class="block text-xs font-normal text-slate-500" lang="bn">{{ m.sub }}</span></button>
                                <div class="grid grid-cols-7 gap-0.5 text-center text-xs">
                                    <span v-for="d in WEEK_ORDER" :key="d" class="text-[10px] uppercase text-slate-400">{{ WEEKDAYS[d].slice(0, 2) }}</span>
                                    <span v-for="n in m.blank" :key="'b' + n"></span>
                                    <button v-for="d in m.list" :key="d" type="button" class="relative rounded py-1" :class="[closedSet.has(d) ? 'bg-slate-200 font-semibold text-red-700 dark:bg-slate-800' : 'hover:bg-brand-50', d === today ? 'ring-2 ring-brand-700' : '']"
                                            :title="(byDay[d] || []).map((x) => x.title).join(', ')" :aria-label="`${label(d, 'both')} ${(byDay[d] || []).map((x) => x.title).join(', ')}`" @click="byDay[d] ? go({ view: 'week', date: d }) : add(d)">
                                        {{ dayNumber(d, mode) }}
                                        <span v-if="byDay[d]" class="absolute bottom-0 left-1/2 flex -translate-x-1/2 gap-px"><span v-for="e in byDay[d].slice(0, 3)" :key="e.id" class="h-1 w-1 rounded-full" :class="DOTS[color(e)]"></span></span>
                                    </button>
                                </div>
                            </div>
                        </section>

                        <!-- list -->
                        <section v-else class="panel overflow-x-auto">
                            <table class="data-table min-w-[640px]">
                                <thead><tr><th>Dates</th><th>Event</th><th>Type</th><th>For</th><th>School</th></tr></thead>
                                <tbody>
                                    <tr v-for="e in events" :key="`${e.id}-${e.starts_on}`" class="cursor-pointer" @click="open(e)">
                                        <td class="whitespace-nowrap">{{ range(e.starts_on, e.ends_on, mode, true) }}</td>
                                        <td><button type="button" class="text-left font-medium hover:underline">{{ e.title }}</button><span v-if="e.title_bn" class="block text-xs text-slate-500" lang="bn">{{ e.title_bn }}</span>
                                            <span v-if="e.tentative" class="badge ml-1 border-amber-300 bg-amber-50 text-amber-800">tentative</span><span v-if="e.repeat !== 'none'" class="badge ml-1 border-slate-300 bg-slate-100 text-slate-600">yearly</span></td>
                                        <td><span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full" :class="DOTS[color(e)]"></span>{{ typeLabel(e.type) }}</span></td>
                                        <td>{{ e.audience === 'all' ? 'Whole school' : e.audience === 'teachers' ? 'Teachers' : e.class_ids.map((i) => classes.find((c) => c.id === i)?.label).filter(Boolean).join(', ') }}</td>
                                        <td>{{ e.off ? 'Closed' : e.type === 'exam' ? 'Exam' : '' }}</td>
                                    </tr>
                                    <tr v-if="!events.length"><td colspan="5" class="px-4 py-10 text-center text-slate-500">No dates{{ filters.types.length || filters.class ? ' match these filters' : ' yet' }}. <button v-if="can.manage" type="button" class="font-medium text-brand-700 underline" @click="dialog = { kind: 'bulk' }">Add many at once</button></td></tr>
                                </tbody>
                            </table>
                        </section>
                    </div>

                    <aside class="space-y-3">
                        <section v-if="tentative.length" class="panel border-l-4 !border-l-amber-500 p-4 text-sm">
                            <h2 class="font-semibold">Waiting to be confirmed</h2>
                            <ul class="mt-2 space-y-2"><li v-for="t in tentative" :key="t.id" class="flex items-center justify-between gap-2"><span class="min-w-0"><span class="block truncate">{{ t.title }}</span><span class="text-xs text-slate-500">{{ range(t.starts_on, t.ends_on, mode, true) }}</span></span><button type="button" class="btn shrink-0" @click="openConfirm(t)">Confirm</button></li></ul>
                        </section>
                        <section class="panel p-4 text-sm">
                            <h2 class="font-semibold">Coming up</h2>
                            <p v-if="nextHoliday" class="mt-2 rounded bg-red-50 px-2 py-1.5 text-red-900">Next holiday: <b>{{ nextHoliday.title }}</b><br />{{ label(nextHoliday.starts_on, mode, false) }} · {{ nextHoliday.in_days === 0 ? 'now' : `in ${nextHoliday.in_days} days` }}</p>
                            <ul class="mt-2 divide-y divide-slate-100 dark:divide-slate-800"><li v-for="e in upcoming" :key="`${e.id}-${e.starts_on}`" class="py-1.5"><button type="button" class="w-full text-left hover:underline" @click="open(e)"><span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 shrink-0 rounded-full" :class="DOTS[color(e)]"></span><span class="truncate">{{ e.title }}</span></span><span class="text-xs text-slate-500">{{ range(e.starts_on, e.ends_on, mode) }}</span></button></li>
                                <li v-if="!upcoming.length" class="py-2 text-slate-500">Nothing in the coming months.</li></ul>
                        </section>
                        <section class="panel p-4 text-sm">
                            <h2 class="font-semibold">Working days {{ num(stats.year) }}</h2>
                            <p class="mt-1 text-2xl font-semibold tabular-nums">{{ num(stats.total) }}</p>
                            <p class="text-xs text-slate-500">Weekly off: {{ WEEK_ORDER.filter((d) => !weekdays.includes(d)).map((d) => WEEKDAYS[d]).join(', ') || 'none' }}</p>
                            <div class="mt-2 grid grid-cols-4 gap-1 text-center"><div v-for="m in stats.months" :key="m.month" class="rounded border border-slate-200 py-1 dark:border-slate-800"><div class="text-[11px] text-slate-500">{{ m.name.slice(0, 3) }}</div><div class="font-semibold tabular-nums">{{ num(m.working) }}</div></div></div>
                            <ul v-if="stats.terms.length" class="mt-2 space-y-1"><li v-for="x in stats.terms" :key="x.id" class="flex justify-between gap-2"><span class="truncate">{{ x.title }} <span class="text-xs text-slate-500">{{ range(x.starts_on, x.ends_on, mode) }}</span></span><b class="tabular-nums">{{ num(x.working) }}</b></li></ul>
                            <p v-else class="mt-2 text-xs text-slate-500">Add a “Term / semester” to see its working days.</p>
                        </section>
                    </aside>
                </div>
            </div>
        </div>

        <EventDialog v-if="dialog?.kind === 'event'" :event="dialog.event" :start="dialog.start" :end="dialog.end" :types="types" :optional-off="optionalOff" :classes="classes" :exams="exams" :weekdays="weekdays" :can="can" @close="dialog = null" />
        <BulkDialog v-if="dialog?.kind === 'bulk'" :types="types" @close="dialog = null" />

        <Dialog v-if="dialog?.kind === 'move'" title="Move this date?" @close="dialog = null">
            <p><b>{{ dialog.event.title }}</b> from {{ label(dialog.event.starts_on, 'both') }} to <b>{{ label(dialog.to, 'both') }}</b>.</p>
            <p v-if="dialog.event.repeat !== 'none'" class="mt-2 text-amber-800">It repeats every year: only {{ dialog.event.starts_on.slice(0, 4) }} is moved.</p>
            <div class="mt-4 flex justify-end gap-2"><button type="button" class="btn" @click="dialog = null">Cancel</button><button type="button" class="btn btn-primary" @click="doMove">Move</button></div>
        </Dialog>

        <Dialog v-if="dialog?.kind === 'confirm'" :title="`Confirm: ${dialog.event.title}`" @close="dialog = null">
            <p class="mb-3">Change the dates if the announced day is different, then confirm. Routine, attendance and teaching plans use the new dates at once.</p>
            <div class="grid grid-cols-1 gap-3"><DateField id="cf-a" v-model="settle.starts_on" label="From" /><DateField id="cf-b" v-model="settle.ends_on" label="To" :min="settle.starts_on" /></div>
            <div class="mt-4 flex justify-end gap-2"><button type="button" class="btn" @click="dialog = null">Cancel</button><button type="button" class="btn btn-primary" @click="doConfirm">Confirm dates</button></div>
        </Dialog>

        <Dialog v-if="dialog?.kind === 'copy'" title="Copy last year’s calendar" wide @close="dialog = null">
            <label class="flex items-center gap-2">Copy from <select v-model.number="copy.from" class="field !w-28" @change="loadCopy"><option v-for="y in [0, 1, 2, 3, 4]" :key="y" :value="Number(date.slice(0, 4)) - y">{{ Number(date.slice(0, 4)) - y }}</option></select> into <b>{{ copy.from + 1 }}</b></label>
            <p class="mt-1 text-xs text-slate-500">The same English date, a year later. Yearly events appear by themselves and need no copy. Moon-dependent holidays should be checked and confirmed.</p>
            <p v-if="copy.error" class="mt-2 text-red-700">{{ copy.error }}</p>
            <p v-else-if="!copy.list" class="mt-3 text-slate-500">Loading…</p>
            <p v-else-if="!copy.list.length" class="mt-3 text-slate-500">There is nothing to copy from {{ copy.from }}.</p>
            <div v-else class="mt-3 max-h-72 overflow-auto rounded-md border border-slate-200">
                <label v-for="x in copy.list" :key="x.id" class="flex items-center gap-2 border-b border-slate-100 px-3 py-1.5"><input v-model="copy.ids" type="checkbox" :value="x.id" :disabled="!!x.skip" /><span class="min-w-0 flex-1"><span class="font-medium">{{ x.data.title }}</span> <span class="text-xs text-slate-500">{{ label(x.from, 'english') }} → {{ range(x.data.starts_on, x.data.ends_on, 'both') }}</span><span v-if="x.skip" class="block text-xs text-amber-700">{{ x.skip }}</span></span></label>
            </div>
            <label class="mt-3 flex items-center gap-1.5"><input v-model="copy.tentative" type="checkbox" />Mark the copied dates tentative until I confirm them</label>
            <div class="mt-4 flex justify-end gap-2"><button type="button" class="btn" @click="dialog = null">Cancel</button><button type="button" class="btn btn-primary" :disabled="!copy.ids.length" @click="doCopy">Copy {{ copy.ids.length }} {{ copy.ids.length === 1 ? 'date' : 'dates' }}</button></div>
        </Dialog>

        <Dialog v-if="dialog?.kind === 'closure'" title="Emergency closure" wide @close="dialog = null">
            <form class="space-y-3" @submit.prevent="doClosure">
                <ul v-if="Object.keys(closure.errors).length" class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-red-800" role="alert"><li v-for="(m, k) in closure.errors" :key="k">{{ m }}</li></ul>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div><label class="field-label" for="cl-r">Reason</label><input id="cl-r" v-model="closure.reason" required maxlength="150" class="field" placeholder="Flood, storm, strike…" /></div>
                    <div><label class="field-label" for="cl-rb">Reason (বাংলা)</label><input id="cl-rb" v-model="closure.reason_bn" maxlength="150" class="field" lang="bn" /></div>
                    <DateField id="cl-a" v-model="closure.starts_on" label="Closed from" required />
                    <DateField id="cl-b" v-model="closure.ends_on" label="Closed to" :min="closure.starts_on" />
                </div>
                <fieldset>
                    <legend class="field-label">What closes?</legend>
                    <label class="mr-4 inline-flex items-center gap-1.5"><input v-model="closure.audience" type="radio" value="all" />The whole school</label>
                    <label class="inline-flex items-center gap-1.5"><input v-model="closure.audience" type="radio" value="classes" />Chosen classes</label>
                    <div v-if="closure.audience === 'classes'" class="mt-2 grid max-h-32 grid-cols-2 gap-1 overflow-auto rounded-md border border-slate-200 p-2 sm:grid-cols-3"><label v-for="c in classes" :key="c.id" class="flex items-center gap-1.5"><input v-model="closure.class_ids" type="checkbox" :value="c.id" />{{ c.label }}</label></div>
                </fieldset>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <DateField id="cl-m" v-model="closure.makeup_on" label="Make-up day (optional)" />
                    <div v-if="closure.makeup_on"><label class="field-label" for="cl-f">Routine it follows</label><select id="cl-f" v-model="closure.follows_day" class="field"><option :value="null">The first closed day ({{ closure.starts_on ? WEEKDAYS[weekday(closure.starts_on)] : '' }})</option><option v-for="d in WEEK_ORDER.filter((x) => weekdays.includes(x))" :key="d" :value="d">{{ WEEKDAYS[d] }}</option></select></div>
                </div>
                <label class="flex items-start gap-1.5"><input v-model="closure.reschedule" type="checkbox" class="mt-1" /><span>Also spread the unfinished chapters of the affected teaching plans again <span class="block text-xs text-slate-500">Leave off to keep every chapter date as it is; “expected by today” is updated either way.</span></span></label>
                <p class="rounded-md bg-slate-100 px-3 py-2 text-xs dark:bg-slate-800">On closed days no classes are listed, attendance cannot be taken or counted, and teaching plans do not expect lessons.</p>
                <div class="flex justify-end gap-2"><button type="button" class="btn" @click="dialog = null">Cancel</button><button class="btn btn-danger" :disabled="!closure.reason || !closure.starts_on || (closure.audience === 'classes' && !closure.class_ids.length)">Close the school for these days</button></div>
            </form>
        </Dialog>

        <Dialog v-if="dialog?.kind === 'off'" title="Weekly off days" @close="dialog = null">
            <p class="mb-3">Tick the days on which the school is normally closed. The routine uses the same setting.</p>
            <label v-for="d in WEEK_ORDER" :key="d" class="flex items-center gap-2 py-1"><input v-model="off" type="checkbox" :value="d" />{{ WEEKDAYS[d] }}</label>
            <div class="mt-4 flex justify-end gap-2"><button type="button" class="btn" @click="dialog = null">Cancel</button><button type="button" class="btn btn-primary" :disabled="off.length > 6" @click="doOff">Save</button></div>
        </Dialog>
    </AuthenticatedLayout>
</template>
