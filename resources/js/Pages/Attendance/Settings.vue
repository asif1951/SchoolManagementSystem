<script setup>
import Shell from '@/Components/Attendance/Shell.vue'
import Dialog from '@/Components/Attendance/Dialog.vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import { reactive, ref } from 'vue'
import { useT } from '@/composables/useT'
import { SOFT, day, num, stamp, useCalendarMode } from '@/lib/attendance'

const props = defineProps({ year: String, years: Array, today: String, settings: Object, school: String, classes: Array, modes: Array, modeHistory: Array, audit: Array })
const t = useT('attendance')
const page = usePage()
const mode = useCalendarMode()
const n = (v) => num(v, page.props.locale)
const label = (id) => props.classes.find((c) => c.id === id)?.label ?? `#${id}`

// ---- modes ------------------------------------------------------------------
const pick = reactive({})                       // scope => { mode, from }
const row = (scope, current) => (pick[scope] ??= { mode: current, from: props.today })
row(0, props.school)
for (const m of props.modes) row(m.class_id, m.own ?? '')
const asking = ref(null)
const change = async (scope) => {
    const data = { scope, mode: pick[scope].mode || null, from: pick[scope].from }
    try {
        const impact = (await window.axios.get(route('attendance.settings.impact'), { params: data })).data
        asking.value = { data, impact }
    } catch (e) { shell.value.notify(Object.values(e.response?.data?.errors ?? {})[0]?.[0] ?? e.message, 'error') }
}
const confirmMode = () => router.post(route('attendance.settings.mode'), asking.value.data, { preserveScroll: true, onFinish: () => { asking.value = null } })
const cancelMode = (id) => router.delete(route('attendance.settings.mode.cancel', id), { preserveScroll: true })
const modeName = (m) => t(`mode.${m || 'default'}`)
const shell = ref(null)

// ---- rules -------------------------------------------------------------------
const form = useForm({ ...props.settings, deadline: props.settings.deadline ?? '', report_periods: props.settings.report_periods.map((p) => ({ ...p })) })
const types = ref(props.settings.leave_types.join('\n'))
const save = () => form.transform((d) => ({ ...d, deadline: d.deadline || null, leave_types: types.value.split('\n').map((x) => x.trim()).filter(Boolean) })).put(route('attendance.settings.update'), { preserveScroll: true })
const setYear = (y) => router.get(route('attendance.settings'), { year: y })
</script>

<template>
    <Shell ref="shell" :title="t('settings_title')" :subtitle="t('settings_sub')">
        <section class="mb-5 rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
            <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
                <h2 class="font-semibold">{{ t('mode_title') }}</h2>
                <label><span class="sr-only">{{ t('year') }}</span><select class="field !w-auto" :value="year" @change="setYear($event.target.value)"><option v-for="y in years" :key="y" :value="y">{{ n(y) }}</option></select></label>
            </div>
            <div class="mb-4 flex flex-wrap items-end gap-2 rounded-md bg-brand-50 p-3 dark:bg-slate-800">
                <p class="mr-2 self-center text-sm"><b>{{ t('school_default') }}:</b> <span class="badge bg-brand-700 text-white">{{ modeName(school) }}</span></p>
                <label><span class="field-label">{{ t('current_mode') }}</span><select v-model="pick[0].mode" class="field !w-auto"><option value="daily">{{ t('mode.daily') }}</option><option value="period">{{ t('mode.period') }}</option></select></label>
                <label><span class="field-label">{{ t('effective_from') }}</span><input v-model="pick[0].from" type="date" :min="today" class="field !w-auto"></label>
                <button type="button" class="btn btn-primary" @click="change(0)">{{ t('change') }}</button>
            </div>
            <div class="max-h-[28rem] overflow-auto">
                <table class="data-table min-w-[640px]">
                    <thead><tr><th>{{ t('class') }}</th><th>{{ t('current_mode') }}</th><th>{{ t('effective_from') }}</th><th>{{ t('change') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="m in modes" :key="m.class_id">
                            <td class="font-medium">{{ label(m.class_id) }}</td>
                            <td><span class="badge" :class="m.mode === 'period' ? 'bg-brand-100 text-brand-900' : SOFT.leave">{{ modeName(m.mode) }}</span><span v-if="!m.own" class="ml-1 text-xs text-slate-500">{{ t('mode.default') }}</span></td>
                            <td class="text-sm">{{ m.from ? day(m.from, mode) : '—' }}
                                <span v-if="m.next" class="block text-xs text-orange-700 dark:text-orange-300">{{ t('scheduled', { mode: modeName(m.next.mode), date: day(m.next.from, mode) }) }} <button type="button" class="underline" @click="cancelMode(m.next.id)">{{ t('remove') }}</button></span></td>
                            <td><span class="flex flex-wrap gap-1">
                                <select v-model="pick[m.class_id].mode" class="field !h-8 !w-auto text-xs" :aria-label="`${label(m.class_id)}: ${t('current_mode')}`"><option value="">{{ t('mode.default') }}</option><option value="daily">{{ t('mode.daily') }}</option><option value="period">{{ t('mode.period') }}</option></select>
                                <input v-model="pick[m.class_id].from" type="date" :min="today" class="field !h-8 !w-auto text-xs" :aria-label="t('effective_from')">
                                <button type="button" class="btn !h-8" @click="change(m.class_id)">{{ t('change') }}</button></span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-xs text-slate-500">{{ t('routine_note') }}</p>
        </section>

        <form class="space-y-5" @submit.prevent="save">
            <div class="grid gap-5 lg:grid-cols-2">
                <fieldset class="space-y-3 rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900"><legend class="px-1 font-semibold">{{ t('general') }}</legend>
                    <label class="block"><span class="field-label">{{ t('set.entry_days') }}</span><input v-model.number="form.entry_days" type="number" min="0" max="365" class="field"></label>
                    <label class="block"><span class="field-label">{{ t('set.edit_days') }}</span><input v-model.number="form.edit_days" type="number" min="0" max="365" class="field"></label>
                    <label class="flex items-center gap-2 text-sm"><input v-model="form.allow_late" type="checkbox"> {{ t('set.allow_late') }}</label>
                    <label class="flex items-center gap-2 text-sm"><input v-model="form.allow_leave" type="checkbox"> {{ t('set.allow_leave') }}</label>
                    <label class="block"><span class="field-label">{{ t('set.leave_types') }}</span><textarea v-model="types" rows="5" class="field" /></label>
                </fieldset>
                <div class="space-y-5">
                    <fieldset class="space-y-3 rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900"><legend class="px-1 font-semibold">{{ t('daily_settings') }}</legend>
                        <label class="block"><span class="field-label">{{ t('set.default_status') }}</span><select v-model="form.default_status" class="field"><option value="present">{{ t('set.default_present') }}</option><option value="none">{{ t('set.default_none') }}</option></select></label>
                        <label class="block"><span class="field-label">{{ t('set.deadline') }}</span><input v-model="form.deadline" type="time" class="field"></label>
                    </fieldset>
                    <fieldset class="space-y-3 rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900"><legend class="px-1 font-semibold">{{ t('period_settings') }}</legend>
                        <label class="flex items-center gap-2 text-sm"><input v-model="form.period_same_day" type="checkbox"> {{ t('set.period_same_day') }}</label>
                        <label class="flex items-center gap-2 text-sm"><input v-model="form.allow_substitute" type="checkbox"> {{ t('set.allow_substitute') }}</label>
                    </fieldset>
                </div>
            </div>
            <fieldset class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900"><legend class="px-1 font-semibold">{{ t('report_settings') }}</legend>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <label><span class="field-label">{{ t('set.low_threshold') }}</span><input v-model.number="form.low_threshold" type="number" min="1" max="100" step="0.5" class="field"></label>
                    <label><span class="field-label">{{ t('set.consecutive_days') }}</span><input v-model.number="form.consecutive_days" type="number" min="2" max="60" class="field"></label>
                    <label><span class="field-label">{{ t('set.default_period') }}</span><select v-model="form.default_period" class="field"><option v-for="p in ['day', 'week', 'month', 'half', 'year']" :key="p" :value="p">{{ t(`period.${p}`) }}</option></select></label>
                    <label><span class="field-label">{{ t('set.date_mode') }}</span><select v-model="form.date_mode" class="field"><option v-for="c in ['english', 'bangla', 'both']" :key="c" :value="c">{{ t(`cal.${c}`) }}</option></select></label>
                </div>
                <p class="field-label mt-4">{{ t('set.report_periods') }}</p>
                <div v-for="(p, i) in form.report_periods" :key="i" class="mb-2 flex flex-wrap gap-2">
                    <input v-model="p.name" class="field !w-48" :placeholder="t('set.name')" :aria-label="t('set.name')" required>
                    <input v-model="p.from" class="field !w-24" placeholder="01-01" :aria-label="t('from')" pattern="(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])" required>
                    <input v-model="p.to" class="field !w-24" placeholder="06-30" :aria-label="t('to')" pattern="(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])" required>
                    <button type="button" class="btn" @click="form.report_periods.splice(i, 1)">{{ t('remove') }}</button>
                </div>
                <button type="button" class="btn" @click="form.report_periods.push({ name: '', from: '', to: '' })">+ {{ t('add') }}</button>
                <p class="mt-1 text-xs text-slate-500">{{ t('set.report_periods_help') }}</p>
            </fieldset>
            <p v-for="(e, k) in form.errors" :key="k" class="text-sm text-red-700">{{ e }}</p>
            <button type="submit" class="btn btn-primary" :disabled="form.processing">{{ t('save') }}</button>
        </form>

        <div class="mt-6 grid gap-5 lg:grid-cols-2">
            <section class="rounded-lg border border-slate-200 bg-white p-4 text-sm dark:border-slate-800 dark:bg-slate-900">
                <h2 class="mb-2 font-semibold">{{ t('mode_history') }}</h2>
                <ul class="max-h-72 space-y-1 overflow-y-auto"><li v-for="h in modeHistory" :key="h.id">{{ day(h.effective_from, mode) }} · {{ h.scope ? label(h.scope) : t('school_default') }} · <b>{{ modeName(h.mode) }}</b> <span class="text-slate-500">· {{ h.by || '—' }}</span></li></ul>
            </section>
            <section class="rounded-lg border border-slate-200 bg-white p-4 text-sm dark:border-slate-800 dark:bg-slate-900">
                <h2 class="mb-2 font-semibold">{{ t('audit') }}</h2>
                <ul class="max-h-72 space-y-1 overflow-y-auto"><li v-for="(a, i) in audit" :key="i">{{ stamp(a.at, page.props.locale) }} · {{ a.by || '—' }} · {{ t(`event.${a.event}`) }} <span class="text-slate-500">{{ a.label }}</span></li></ul>
            </section>
        </div>

        <Dialog v-if="asking" :title="t('mode_warning_title')" @close="asking = null">
            <p><b>{{ asking.data.scope ? label(asking.data.scope) : t('school_default') }}</b> → {{ modeName(asking.data.mode) }} · {{ day(asking.data.from, mode) }}</p>
            <p v-if="asking.impact.conflict_until" class="mt-3 rounded bg-red-50 p-2 text-red-900">{{ t('mode_blocked', { date: day(asking.impact.conflict_until, mode) }) }}</p>
            <p v-else class="mt-3 rounded bg-orange-50 p-2 text-orange-950">⚠ {{ t('mode_warning', { classes: n(asking.impact.classes), sessions: n(asking.impact.past_sessions) }) }}</p>
            <div class="mt-4 flex justify-end gap-2"><button type="button" class="btn" @click="asking = null">{{ t('cancel') }}</button><button type="button" class="btn btn-primary" :disabled="!!asking.impact.conflict_until" @click="confirmMode">{{ t('confirm') }}</button></div>
        </Dialog>
    </Shell>
</template>
