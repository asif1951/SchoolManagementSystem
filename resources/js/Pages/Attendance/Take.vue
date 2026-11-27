<script setup>
import Shell from '@/Components/Attendance/Shell.vue'
import Dialog from '@/Components/Attendance/Dialog.vue'
import CalendarStrip from '@/Components/Calendar/CalendarStrip.vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useT } from '@/composables/useT'
import { ICONS, ON, SOFT, day, num, stamp, useCalendarMode } from '@/lib/attendance'

const props = defineProps({
    year: String, years: Array, date: String, classes: Array,
    classInfo: { type: Object, default: null }, sheet: { type: Object, default: null }, conflicts: { type: Array, default: () => [] }, defaultStatus: String,
})
const t = useT('attendance')
const page = usePage()
const mode = useCalendarMode()
const n = (v) => num(v, page.props.locale)
const can = (key) => { const l = page.props.auth?.abilities ?? []; return l.includes('*') || l.includes(key) || l.includes(`${key}:own`) }

// ---- choosing -------------------------------------------------------------
const go = (params) => router.get(route('attendance.index'), params, { preserveScroll: true, preserveState: false })
const pickDate = (d) => d && go(props.classInfo ? { class: props.classInfo.id, date: d } : { date: d })
const pickYear = (y) => go({ year: y, date: y === props.date.slice(0, 4) ? props.date : `${y}-12-31` })
const open = (id) => go({ class: id, date: props.date })
const openPeriod = (p) => go({ class: props.classInfo.id, date: props.date, period: p })

// ---- marking --------------------------------------------------------------
const marks = reactive({})
const remarks = reactive({})
const dirty = ref(false)
const reset = () => {
    Object.keys(marks).forEach((k) => delete marks[k]); Object.keys(remarks).forEach((k) => delete remarks[k])
    for (const s of props.sheet?.students ?? []) { marks[s.id] = s.status; remarks[s.id] = s.remark ?? '' }
    dirty.value = false
}
watch(() => props.sheet, reset, { immediate: true })

const editable = computed(() => !!props.sheet?.can.save)
const submitted = computed(() => !!props.sheet?.session)
const search = ref('')
const shown = computed(() => {
    const q = search.value.trim().toLowerCase()
    return (props.sheet?.students ?? []).filter((s) => !q || s.name.toLowerCase().includes(q) || String(s.roll ?? '') === q || String(s.code ?? '').includes(q))
})
const counts = computed(() => {
    const c = { total: props.sheet?.students.length ?? 0, present: 0, absent: 0, late: 0, leave: 0, unmarked: 0 }
    for (const s of props.sheet?.students ?? []) marks[s.id] ? c[marks[s.id]]++ : c.unmarked++
    return c
})
const choices = (s) => ['present', 'absent', 'late', 'leave'].filter((k) => props.sheet.statuses.includes(k) || marks[s.id] === k || (k === 'leave' && s.leave))
const set = (id, status) => { if (!editable.value) return; marks[id] = status; dirty.value = true }
// students on approved leave stay on leave
const allPresent = () => { for (const s of props.sheet.students) if (!s.leave) marks[s.id] = 'present'; dirty.value = true }

const confirming = ref(false)
const saving = ref(false)
const submit = () => {
    saving.value = true
    dirty.value = false
    router.post(route('attendance.store'), {
        class_id: props.classInfo.id, date: props.date, period_id: props.sheet.period?.period_id ?? null, revision: props.sheet.session?.revision ?? null,
        entries: props.sheet.students.map((s) => ({ student_id: s.id, status: marks[s.id], remark: remarks[s.id] || null })),
    }, { preserveScroll: true, onError: () => { dirty.value = true }, onFinish: () => { saving.value = false; confirming.value = false } })
}

// marks that were not submitted are not lost by a stray tap
const stop = router.on('before', (e) => { if (dirty.value && e.detail.visit.method === 'get' && !window.confirm(t('unsaved_leave'))) e.preventDefault(); else if (e.detail.visit.method === 'get') dirty.value = false })
const warn = (e) => { if (dirty.value) { e.preventDefault(); e.returnValue = '' } }
onMounted(() => window.addEventListener('beforeunload', warn))
onBeforeUnmount(() => { stop(); window.removeEventListener('beforeunload', warn) })

const printUrl = (type) => route('attendance.print.sheet', { type, class: props.classInfo.id, date: props.date, period: props.sheet?.period?.period_id, cal: mode.value })
const stateClass = { taken: SOFT.present, submitted: SOFT.present, partial: SOFT.partial, pending: SOFT.late, closed: SOFT.leave, none: SOFT.leave, not_taken: SOFT.absent }
</script>

<template>
    <Shell :title="classInfo ? `${t('take_title')} · ${classInfo.label}` : t('take_title')" :subtitle="classInfo ? day(date, mode) : t('take_sub')">
        <template #actions>
            <label v-if="!classInfo"><span class="field-label">{{ t('year') }}</span>
                <select class="field !w-auto" :value="year" @change="pickYear($event.target.value)"><option v-for="y in years" :key="y" :value="y">{{ n(y) }}</option></select>
            </label>
            <label><span class="field-label">{{ t('date') }}</span><input type="date" class="field !w-auto" :value="date" @change="pickDate($event.target.value)"></label>
        </template>

        <CalendarStrip v-if="!classInfo" today-only />

        <!-- the classes -->
        <template v-if="!classInfo">
            <p v-if="!classes.length" class="rounded-lg border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">{{ t('no_classes') }}</p>
            <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <li v-for="c in classes" :key="c.id">
                    <button type="button" class="w-full rounded-lg border border-slate-200 bg-white p-4 text-left hover:border-brand-500 hover:shadow dark:border-slate-800 dark:bg-slate-900" @click="open(c.id)">
                        <span class="flex items-start justify-between gap-2">
                            <span class="font-semibold text-slate-900 dark:text-white">{{ c.label }}</span>
                            <span v-if="c.status" class="badge shrink-0" :class="stateClass[c.status.state]">{{ t(`state.${c.status.state}`) }}</span>
                        </span>
                        <span class="mt-1 block text-xs text-slate-500">{{ c.teacher || '—' }}</span>
                        <span v-if="c.status" class="mt-2 block text-xs text-slate-500">{{ t(`mode.${c.status.mode}`) }}<template v-if="c.status.mode === 'period'"> · {{ n(c.status.done) }}/{{ n(c.status.due) }}</template><template v-if="c.status.reason && !c.status.working"> · {{ c.status.reason }}</template></span>
                    </button>
                </li>
            </ul>
            <section v-if="conflicts.length" class="mt-6 rounded-lg border border-orange-300 bg-orange-50 p-4 text-sm text-orange-950 dark:border-orange-800 dark:bg-orange-950 dark:text-orange-100">
                <h2 class="font-semibold">{{ t('conflicts_title') }}</h2>
                <p class="mb-2 text-xs">{{ t('conflicts_text') }}</p>
                <ul class="space-y-1">
                    <li v-for="c in conflicts" :key="c.session_id"><Link class="underline" :href="route('attendance.index', { class: c.class_id, date: c.date })">{{ day(c.date, mode) }}</Link> · {{ classes.find((x) => x.id === c.class_id)?.label }} {{ c.period }} · {{ c.reason }}</li>
                </ul>
            </section>
        </template>

        <!-- one class -->
        <template v-else-if="sheet">
            <div class="mb-3 flex flex-wrap items-center gap-2">
                <Link class="btn" :href="route('attendance.index', { date })">← {{ t('back_to_classes') }}</Link>
                <span class="badge bg-brand-100 text-brand-900 dark:bg-brand-900 dark:text-brand-100">{{ t(`mode.${sheet.mode}`) }}</span>
                <span class="flex-1" />
                <template v-if="can('attendance.print')">
                    <a v-if="sheet.mode === 'daily'" class="btn" :href="printUrl('daily')" target="_blank" rel="noopener">⎙ {{ t('print_daily') }}</a>
                    <template v-else>
                        <a v-if="sheet.period" class="btn" :href="printUrl('period')" target="_blank" rel="noopener">⎙ {{ t('print_period') }}</a>
                        <a class="btn" :href="printUrl('fullday')" target="_blank" rel="noopener">⎙ {{ t('print_fullday') }}</a>
                    </template>
                </template>
            </div>

            <p v-if="!sheet.day.working" class="mb-3 rounded-md border border-red-300 bg-red-50 px-4 py-2.5 text-sm font-medium text-red-900 dark:border-red-900 dark:bg-red-950 dark:text-red-100" role="status">
                {{ t('closed_note', { reason: sheet.day.reason }) }} <span v-if="editable" class="block font-normal">{{ t('override_note') }}</span>
            </p>

            <section v-if="sheet.mode === 'period'" class="mb-4">
                <h2 class="mb-2 text-sm font-semibold">{{ t('periods_title') }}</h2>
                <p v-if="!sheet.periods.length" class="rounded-lg border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">{{ t('no_periods') }}</p>
                <ul class="flex gap-2 overflow-x-auto pb-1">
                    <li v-for="p in sheet.periods" :key="p.period_id" class="shrink-0">
                        <button type="button" class="w-44 rounded-lg border bg-white p-3 text-left text-sm dark:bg-slate-900" :class="sheet.period?.period_id === p.period_id ? 'border-brand-700 ring-2 ring-brand-200 dark:ring-brand-800' : 'border-slate-200 hover:border-brand-400 dark:border-slate-800'" :aria-pressed="sheet.period?.period_id === p.period_id" @click="openPeriod(p.period_id)">
                            <span class="flex justify-between gap-1 text-xs text-slate-500"><span>{{ p.period_name }}</span><span>{{ n(p.starts_at) }}–{{ n(p.ends_at) }}</span></span>
                            <span class="block truncate font-semibold text-slate-900 dark:text-white">{{ p.subject_name }}</span>
                            <span class="block truncate text-xs text-slate-500">{{ p.teacher_name || '—' }}<b v-if="p.mine" class="ml-1 text-brand-700 dark:text-brand-300">· {{ t('mine') }}</b></span>
                            <span class="badge mt-1" :class="stateClass[p.state]">{{ t(`state.${p.state}`) }}</span>
                        </button>
                    </li>
                </ul>
                <p v-if="sheet.periods.length && !sheet.period" class="mt-2 text-sm text-slate-500">{{ t('choose_period') }}</p>
            </section>

            <template v-if="sheet.mode === 'daily' || sheet.period">
                <div v-if="submitted" class="mb-3 rounded-md border border-green-300 bg-green-50 px-4 py-2.5 text-sm text-green-900 dark:border-green-900 dark:bg-green-950 dark:text-green-100" role="status">
                    <b>✓ {{ t('already_done') }}</b>
                    <span class="block text-xs">
                        <template v-if="sheet.session.source === 'legacy'">{{ t('from_old_system') }} · </template>
                        {{ t('submitted_by', { name: sheet.session.taken_by || '—', at: stamp(sheet.session.submitted_at, page.props.locale) }) }}
                        <template v-if="sheet.session.updated_by"> · {{ t('updated_by', { name: sheet.session.updated_by, at: stamp(sheet.session.updated_at, page.props.locale) }) }}</template>
                        <template v-if="sheet.session.late"> · {{ t('late_submitted') }}</template>
                        <template v-if="sheet.session.substitute"> · {{ t('substitute') }}</template>
                    </span>
                </div>
                <p v-if="!editable && sheet.can.reason" class="mb-3 rounded-md border border-slate-300 bg-slate-100 px-4 py-2.5 text-sm dark:border-slate-700 dark:bg-slate-800">{{ t('view_only') }}: {{ sheet.can.reason }}</p>

                <p v-if="!sheet.students.length" class="rounded-lg border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">{{ t('empty_students') }}</p>
                <template v-else>
                    <div class="mb-2 flex flex-wrap items-center gap-2">
                        <input v-model="search" type="search" class="field !w-full sm:!w-64" :placeholder="t('search_student')" :aria-label="t('search_student')">
                        <button v-if="editable" type="button" class="btn btn-primary" @click="allPresent">✓ {{ t('mark_all_present') }}</button>
                    </div>
                    <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200 bg-white dark:divide-slate-800 dark:border-slate-800 dark:bg-slate-900">
                        <li v-for="s in shown" :key="s.id" class="flex flex-wrap items-center gap-x-3 gap-y-2 px-3 py-2">
                            <span class="w-10 shrink-0 text-center text-sm font-semibold tabular-nums text-slate-500">{{ s.roll ? n(s.roll) : '—' }}</span>
                            <span class="min-w-0 flex-1 basis-40">
                                <span class="block truncate text-sm font-medium text-slate-900 dark:text-white">{{ s.name }}</span>
                                <span v-if="s.leave" class="block text-xs text-orange-700 dark:text-orange-300">{{ t('on_leave', { type: s.leave }) }}</span>
                            </span>
                            <input v-if="editable || remarks[s.id]" v-model="remarks[s.id]" type="text" maxlength="255" class="field !h-8 !w-28 text-xs sm:!w-44" :placeholder="t('remark_add')" :aria-label="`${t('remark_add')}: ${s.name}`" :disabled="!editable" @input="dirty = true">
                            <span class="flex gap-1" role="radiogroup" :aria-label="s.name">
                                <button v-for="k in choices(s)" :key="k" type="button" role="radio" :aria-checked="marks[s.id] === k" :disabled="!editable" :title="t(`status.${k}`)"
                                        class="flex h-10 min-w-[3rem] items-center justify-center gap-1 rounded-md border px-2 text-sm font-semibold disabled:cursor-default"
                                        :class="marks[s.id] === k ? ON[k] : 'border-slate-300 text-slate-500 hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800'" @click="set(s.id, k)">
                                    <span aria-hidden="true">{{ ICONS[k] }}</span><span class="hidden md:inline">{{ t(`status.${k}`) }}</span><span class="md:hidden">{{ t(`short.${k}`) }}</span>
                                </button>
                            </span>
                        </li>
                    </ul>

                    <div class="sticky bottom-0 z-20 mt-3 flex flex-wrap items-center gap-2 rounded-lg border border-slate-200 bg-white/95 p-3 shadow-lg backdrop-blur dark:border-slate-700 dark:bg-slate-900/95">
                        <span class="badge bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-100">{{ t('total') }} {{ n(counts.total) }}</span>
                        <span v-for="k in ['present', 'absent', 'late', 'leave']" :key="k" class="badge" :class="SOFT[k]">{{ ICONS[k] }} {{ t(`status.${k}`) }} {{ n(counts[k]) }}</span>
                        <span v-if="counts.unmarked" class="badge bg-orange-200 text-orange-950">{{ t('unmarked') }} {{ n(counts.unmarked) }}</span>
                        <span class="flex-1" />
                        <button v-if="editable" type="button" class="btn btn-primary !h-11 w-full sm:w-auto" :disabled="saving || counts.unmarked > 0 || (submitted && !dirty)" @click="confirming = true">{{ submitted ? t('update') : t('submit') }}</button>
                    </div>
                </template>

                <details v-if="sheet.session?.history.length" class="mt-4 rounded-lg border border-slate-200 bg-white p-3 text-sm dark:border-slate-800 dark:bg-slate-900">
                    <summary class="cursor-pointer font-semibold">{{ t('history') }}</summary>
                    <ul class="mt-2 space-y-1 text-xs">
                        <li v-for="(h, i) in sheet.session.history" :key="i">{{ stamp(h.at, page.props.locale) }} · {{ h.by || '—' }} · {{ t(`event.${h.event}`) }}
                            <span v-for="c in h.changes?.changes ?? []" :key="c.student" class="block pl-4 text-slate-500">{{ sheet.students.find((s) => s.id === c.student)?.name ?? c.student }}: {{ t(`status.${c.from}`) }} → {{ t(`status.${c.to}`) }}</span>
                        </li>
                    </ul>
                </details>
            </template>
        </template>

        <Dialog v-if="confirming" :title="submitted ? t('confirm_update_title') : t('confirm_submit_title')" @close="confirming = false">
            <p>{{ classInfo.label }} · {{ day(date, mode) }}<template v-if="sheet.period"> · {{ sheet.period.period_name }} {{ sheet.period.subject_name }}</template></p>
            <p class="mt-1">{{ t('confirm_submit_text', { n: n(counts.total) }) }}</p>
            <div class="mt-3 flex flex-wrap gap-2"><span v-for="k in ['present', 'absent', 'late', 'leave']" :key="k" class="badge" :class="SOFT[k]">{{ t(`status.${k}`) }} {{ n(counts[k]) }}</span></div>
            <div class="mt-5 flex justify-end gap-2">
                <button type="button" class="btn" @click="confirming = false">{{ t('cancel') }}</button>
                <button type="button" class="btn btn-primary" :disabled="saving" @click="submit">{{ t('confirm') }}</button>
            </div>
        </Dialog>
    </Shell>
</template>
