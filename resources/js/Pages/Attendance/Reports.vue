<script setup>
import Shell from '@/Components/Attendance/Shell.vue'
import TaskProgress from '@/Components/StudentModule/TaskProgress.vue'
import { router, usePage } from '@inertiajs/vue3'
import { computed, reactive, ref, watch } from 'vue'
import { useT } from '@/composables/useT'
import { SOFT, day, num, useCalendarMode } from '@/lib/attendance'

const props = defineProps({ year: String, years: Array, type: String, report: Object, pagination: Object, types: Object, filters: Object, options: Object, canExport: Boolean, canPrint: Boolean })
const t = useT('attendance')
const page = usePage()
const mode = useCalendarMode()
const n = (v) => num(v, page.props.locale)

const blank = () => ({ period: 'month', date: '', from: '', to: '', class_name: '', class_id: '', group_id: '', shift_id: '', student_id: '', subject_id: '', period_id: '', status: 'absent', search: '', sort: '', dir: 'asc' })
const f = reactive({ ...blank(), ...props.filters, type: props.type, year: props.year })
// half-yearly and terms come from the calendar: the chosen part is the period itself
const preset = ref(/^(term|part|half:)/.test(f.period) ? 'half' : f.period)
watch(preset, (p) => { f.period = p === 'half' ? (props.options.parts[0]?.key ?? 'half') : p })

const group = computed(() => props.types[f.type])
const needs = (k) => ({
    student: ['student', 'student_subjects', 'status'].includes(f.type), subject: group.value === 'period' || f.type === 'status', period: f.type === 'periods' || f.type === 'status', status: f.type === 'status',
}[k])
const groups = computed(() => ['time', 'student', 'class', 'status', 'period'].map((g) => ({ g, list: Object.keys(props.types).filter((k) => props.types[k] === g) })).filter((x) => x.list.length))
const sections = computed(() => props.options.classes.filter((c) => !f.class_name || c.class_name === f.class_name))
const classNames = computed(() => [...new Set(props.options.classes.map((c) => c.class_name))])

const params = (extra = {}) => {
    const all = { ...f, ...extra }
    if (!needs('student')) delete all.student_id
    if (!needs('subject')) delete all.subject_id
    if (!needs('period')) delete all.period_id
    if (!needs('status')) delete all.status
    if (all.period !== 'custom') { delete all.from; delete all.to }
    return Object.fromEntries(Object.entries(all).filter(([, v]) => v !== '' && v !== null && v !== undefined))
}
const run = (extra = {}) => router.get(route('attendance.reports'), params({ page: 1, ...extra }), { preserveState: true, preserveScroll: true })
const typeChanged = () => { f.sort = ''; f.search = ''; run() }
const yearChanged = () => { Object.assign(f, { class_name: '', class_id: '', student_id: '', date: '', from: '', to: '' }); if (preset.value === 'half') preset.value = 'month'; run() }
const sortBy = (k) => { f.dir = f.sort === k && f.dir === 'asc' ? 'desc' : 'asc'; f.sort = k; run() }

// ---- the student filter ----------------------------------------------------------
const picked = ref(props.options.student)
const query = ref('')
const found = ref([])
let timer = null
const find = () => { clearTimeout(timer); timer = setTimeout(async () => { found.value = query.value.trim() ? (await window.axios.get(route('attendance.reports.students'), { params: { q: query.value, year: f.year } })).data : [] }, 300) }
const choose = (s) => { picked.value = s; f.student_id = s?.id ?? ''; query.value = ''; found.value = []; run() }

// ---- export ----------------------------------------------------------------------
const task = ref(null)
const busy = ref(false)
const problem = ref('')
const exportAs = async (format) => {
    busy.value = true; problem.value = ''; task.value = null
    try {
        const res = await window.axios.get(route('attendance.reports.export'), { params: params({ format, cal: mode.value }), responseType: 'blob' })
        if (String(res.headers['content-type']).includes('json')) task.value = { id: JSON.parse(await res.data.text()).task, done: false }
        else {
            const a = document.createElement('a')
            a.href = URL.createObjectURL(res.data)
            a.download = /filename="?([^";]+)/.exec(res.headers['content-disposition'] ?? '')?.[1] ?? `attendance.${format}`
            a.click()
            setTimeout(() => URL.revokeObjectURL(a.href), 2000)
        }
    } catch { problem.value = t('export_failed') } finally { busy.value = false }
}
const finished = (s) => { if (s.status === 'done') task.value.done = true; else problem.value = t('export_failed') }
const cards = ['working_days', 'students', 'sessions', 'present', 'absent', 'late', 'leave', 'percent']
</script>

<template>
    <Shell :title="t('reports_title')" :subtitle="t('reports_sub')">
        <template #actions>
            <a v-if="canPrint" class="btn" target="_blank" rel="noopener" :href="route('attendance.print.report', params({ cal: mode }))">⎙ {{ t('print') }}</a>
            <template v-if="canExport"><button type="button" class="btn" :disabled="busy" @click="exportAs('xlsx')">⇩ {{ t('excel') }}</button><button type="button" class="btn" :disabled="busy" @click="exportAs('csv')">⇩ {{ t('csv') }}</button></template>
        </template>

        <form class="mb-4 grid gap-2 rounded-lg border border-slate-200 bg-white p-3 sm:grid-cols-3 lg:grid-cols-5 dark:border-slate-800 dark:bg-slate-900" @submit.prevent="run()">
            <label class="sm:col-span-2"><span class="field-label">{{ t('report_type') }}</span>
                <select v-model="f.type" class="field font-semibold" @change="typeChanged"><optgroup v-for="x in groups" :key="x.g" :label="t(`report_group.${x.g}`)"><option v-for="k in x.list" :key="k" :value="k">{{ t(`report.${k}`) }}</option></optgroup></select></label>
            <label><span class="field-label">{{ t('year') }}</span><select v-model="f.year" class="field" @change="yearChanged"><option v-for="y in years" :key="y" :value="y">{{ n(y) }}</option></select></label>
            <label><span class="field-label">{{ t('reporting_period') }}</span><select v-model="preset" class="field"><option v-for="p in ['day', 'week', 'month', 'half', 'year', 'custom']" :key="p" :value="p">{{ t(`period.${p}`) }}</option></select></label>
            <label v-if="['day', 'week', 'month'].includes(preset)"><span class="field-label">{{ t('date') }}</span><input v-model="f.date" type="date" class="field"></label>
            <label v-if="preset === 'half'"><span class="field-label">{{ t('period.half') }}</span><select v-model="f.period" class="field"><option v-for="p in options.parts" :key="p.key" :value="p.key">{{ p.name }}</option></select></label>
            <template v-if="preset === 'custom'">
                <label><span class="field-label">{{ t('from') }}</span><input v-model="f.from" type="date" class="field" required></label>
                <label><span class="field-label">{{ t('to') }}</span><input v-model="f.to" type="date" class="field" required></label>
            </template>
            <label><span class="field-label">{{ t('class') }}</span><select v-model="f.class_name" class="field" @change="f.class_id = ''"><option value="">{{ t('all') }}</option><option v-for="c in classNames" :key="c" :value="c">{{ c }}</option></select></label>
            <label><span class="field-label">{{ t('section') }}</span><select v-model="f.class_id" class="field"><option value="">{{ t('all') }}</option><option v-for="c in sections" :key="c.id" :value="c.id">{{ c.label }}</option></select></label>
            <label v-if="options.groups.length"><span class="field-label">{{ t('group') }}</span><select v-model="f.group_id" class="field"><option value="">{{ t('all') }}</option><option v-for="g in options.groups" :key="g.id" :value="g.id">{{ g.name }}</option></select></label>
            <label v-if="options.shifts.length"><span class="field-label">{{ t('shift') }}</span><select v-model="f.shift_id" class="field"><option value="">{{ t('all') }}</option><option v-for="s in options.shifts" :key="s.id" :value="s.id">{{ s.name }}</option></select></label>
            <div v-if="needs('student')" class="relative">
                <span class="field-label">{{ t('student') }}</span>
                <p v-if="picked && f.student_id" class="field flex items-center justify-between"><span class="truncate">{{ picked.student_name }}</span><button type="button" :aria-label="t('remove')" @click="choose(null)">×</button></p>
                <input v-else v-model="query" type="search" class="field" :placeholder="t('find_student')" :aria-label="t('student')" @input="find">
                <ul v-if="found.length" class="absolute z-30 mt-1 max-h-52 w-full overflow-y-auto rounded-md border border-slate-200 bg-white shadow-lg dark:border-slate-700 dark:bg-slate-900"><li v-for="s in found" :key="s.id"><button type="button" class="block w-full px-3 py-1.5 text-left text-sm hover:bg-brand-50 dark:hover:bg-slate-800" @click="choose(s)">{{ s.student_name }} · {{ s.roll_number }} · {{ s.student_id }}</button></li></ul>
            </div>
            <label v-if="needs('subject') && options.subjects.length"><span class="field-label">{{ t('subject') }}</span><select v-model="f.subject_id" class="field"><option value="">{{ t('all') }}</option><option v-for="s in options.subjects" :key="s.id" :value="s.id">{{ s.name }}</option></select></label>
            <label v-if="needs('period') && options.periods.length"><span class="field-label">{{ t('period_name') }}</span><select v-model="f.period_id" class="field"><option value="">{{ t('all') }}</option><option v-for="p in options.periods" :key="p.id" :value="p.id">{{ p.name }}</option></select></label>
            <label v-if="needs('status')"><span class="field-label">{{ t('status_filter') }}</span><select v-model="f.status" class="field"><option v-for="s in ['present', 'absent', 'late', 'leave']" :key="s" :value="s">{{ t(`status.${s}`) }}</option></select></label>
            <div class="flex items-end"><button type="submit" class="btn btn-primary w-full">{{ t('show') }}</button></div>
        </form>

        <div v-if="busy || task || problem" class="mb-3 rounded-lg border border-slate-200 bg-white p-3 text-sm dark:border-slate-800 dark:bg-slate-900">
            <p v-if="busy">{{ t('preparing') }}</p>
            <TaskProgress v-if="task" :key="task.id" :task-id="task.id" route-name="attendance.reports.task" @finished="finished" />
            <a v-if="task?.done" class="btn btn-primary mt-2" :href="route('attendance.reports.download', task.id)">⇩ {{ t('download') }}</a>
            <p v-if="problem" class="text-red-700">{{ problem }}</p>
        </div>

        <p class="mb-2 text-sm"><b>{{ report.title }}</b> · {{ report.range.label }} · {{ day(report.range.from, mode) }}<template v-if="report.range.to !== report.range.from"> – {{ day(report.range.to, mode) }}</template></p>
        <dl class="mb-3 grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-8">
            <div v-for="c in cards" :key="c" class="rounded-lg border border-slate-200 bg-white px-3 py-2 dark:border-slate-800 dark:bg-slate-900">
                <dt class="text-xs text-slate-500">{{ t(`card.${c}`) }}</dt>
                <dd class="text-lg font-semibold tabular-nums">{{ report.cards[c] === null ? '—' : n(report.cards[c]) }}<template v-if="c === 'percent' && report.cards[c] !== null">%</template></dd>
            </div>
        </dl>
        <p v-if="['low', 'low_subjects'].includes(type)" class="mb-2 text-xs text-slate-500">{{ t('threshold_note', { n: n(options.threshold) }) }}</p>

        <p v-if="report.note" class="mb-3 rounded-md border border-orange-300 bg-orange-50 px-3 py-2 text-xs text-orange-950 dark:border-orange-800 dark:bg-orange-950 dark:text-orange-100">{{ report.note }}</p>
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <form class="flex gap-2" @submit.prevent="run()"><input v-model="f.search" type="search" class="field !w-56" :placeholder="t('search')" :aria-label="t('search')"><button type="submit" class="btn">{{ t('search') }}</button></form>
            <span class="text-xs text-slate-500">{{ t('results', { n: n(pagination.total) }) }}</span>
        </div>
        <p v-if="type === 'student' && !filters.student_id" class="rounded-lg border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">{{ t('pick_student') }}</p>
        <p v-else-if="!report.rows.length" class="rounded-lg border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">{{ t('no_data') }}</p>
        <div v-else class="overflow-x-auto rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <table class="data-table">
                <thead><tr><th v-for="c in report.columns" :key="c.key" :class="['num', 'pct'].includes(c.type) && 'text-right'" :aria-sort="filters.sort === c.key ? (filters.dir === 'desc' ? 'descending' : 'ascending') : undefined">
                    <button type="button" class="font-semibold hover:underline" @click="sortBy(c.key)">{{ c.label }} <span v-if="filters.sort === c.key" aria-hidden="true">{{ filters.dir === 'desc' ? '↓' : '↑' }}</span></button></th></tr></thead>
                <tbody>
                    <tr v-for="(r, i) in report.rows" :key="i">
                        <td v-for="c in report.columns" :key="c.key" :class="['num', 'pct'].includes(c.type) && 'text-right tabular-nums'">
                            <template v-if="c.type === 'date'">{{ day(r[c.key], mode) }}</template>
                            <span v-else-if="c.type === 'status'" class="badge" :class="SOFT[r[c.key]]">{{ t(`status.${r[c.key]}`) }}</span>
                            <template v-else-if="c.type === 'pct'">{{ r[c.key] === null ? '—' : `${n(r[c.key])}%` }}</template>
                            <template v-else-if="c.type === 'num' || c.key === 'roll'">{{ n(r[c.key]) }}</template>
                            <template v-else>{{ r[c.key] }}</template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <nav v-if="pagination.pages > 1" class="mt-3 flex items-center justify-between text-sm">
            <button type="button" class="btn" :disabled="pagination.page <= 1" @click="run({ page: pagination.page - 1 })">← {{ t('previous') }}</button>
            <span>{{ t('page_of', { page: n(pagination.page), pages: n(pagination.pages) }) }}</span>
            <button type="button" class="btn" :disabled="pagination.page >= pagination.pages" @click="run({ page: pagination.page + 1 })">{{ t('next') }} →</button>
        </nav>
    </Shell>
</template>
