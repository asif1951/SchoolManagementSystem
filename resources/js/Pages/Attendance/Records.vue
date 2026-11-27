<script setup>
import Shell from '@/Components/Attendance/Shell.vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import { reactive } from 'vue'
import { useT } from '@/composables/useT'
import { SOFT, day, num, stamp, useCalendarMode } from '@/lib/attendance'

const props = defineProps({ year: String, years: Array, classes: Array, filters: Object, sessions: Object, conflicts: Array })
const t = useT('attendance')
const page = usePage()
const mode = useCalendarMode()
const n = (v) => num(v, page.props.locale)
const f = reactive({ year: props.year, ...props.filters })
const apply = () => router.get(route('attendance.records'), Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '' && v !== null)), { preserveState: true, preserveScroll: true })
const yearChanged = () => { f.class = ''; f.from = `${f.year}-01-01`; f.to = `${f.year}-12-31`; apply() }
</script>

<template>
    <Shell :title="t('records_title')" :subtitle="t('records_sub')">
        <form class="mb-4 grid gap-2 rounded-lg border border-slate-200 bg-white p-3 sm:grid-cols-3 lg:grid-cols-5 dark:border-slate-800 dark:bg-slate-900" @submit.prevent="apply">
            <label><span class="field-label">{{ t('year') }}</span><select v-model="f.year" class="field" @change="yearChanged"><option v-for="y in years" :key="y" :value="y">{{ n(y) }}</option></select></label>
            <label><span class="field-label">{{ t('class') }}</span><select v-model="f.class" class="field" @change="apply"><option value="">{{ t('all_classes') }}</option><option v-for="c in classes" :key="c.id" :value="c.id">{{ c.label }}</option></select></label>
            <label><span class="field-label">{{ t('from') }}</span><input v-model="f.from" type="date" class="field" @change="apply"></label>
            <label><span class="field-label">{{ t('to') }}</span><input v-model="f.to" type="date" class="field" @change="apply"></label>
            <label><span class="field-label">{{ t('current_mode') }}</span><select v-model="f.mode" class="field" @change="apply"><option value="">{{ t('all_modes') }}</option><option value="daily">{{ t('mode.daily') }}</option><option value="period">{{ t('mode.period') }}</option></select></label>
        </form>

        <section v-if="conflicts.length" class="mb-4 rounded-lg border border-orange-300 bg-orange-50 p-4 text-sm text-orange-950 dark:border-orange-800 dark:bg-orange-950 dark:text-orange-100">
            <h2 class="font-semibold">{{ t('conflicts_title') }}</h2><p class="mb-2 text-xs">{{ t('conflicts_text') }}</p>
            <ul><li v-for="c in conflicts" :key="c.session_id">{{ day(c.date, mode) }} · {{ c.class }} {{ c.period }} · {{ c.reason }}</li></ul>
        </section>

        <p v-if="!sessions.data.length" class="rounded-lg border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">{{ t('no_records') }}</p>
        <div v-else class="overflow-x-auto rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <table class="data-table min-w-[860px]">
                <thead><tr><th>{{ t('date') }}</th><th>{{ t('class') }}</th><th>{{ t('period_name') }}</th><th v-for="k in ['present', 'absent', 'late', 'leave', 'total']" :key="k" class="text-right">{{ t(`col.${k}`) }}</th><th class="text-right">%</th><th>{{ t('taken_by') }}</th><th /></tr></thead>
                <tbody>
                    <tr v-for="s in sessions.data" :key="s.id">
                        <td class="whitespace-nowrap">{{ day(s.date, mode) }}</td>
                        <td>{{ s.class }}</td>
                        <td>{{ s.mode === 'daily' ? t('mode.daily') : `${s.period} · ${s.subject ?? ''}` }}<span v-if="s.substitute" class="badge ml-1" :class="SOFT.partial">{{ t('substitute') }}</span></td>
                        <td v-for="k in ['present', 'absent', 'late', 'leave', 'total']" :key="k" class="text-right tabular-nums">{{ n(s[k]) }}</td>
                        <td class="text-right font-semibold tabular-nums">{{ s.percent === null ? '—' : `${n(s.percent)}%` }}</td>
                        <td class="text-xs">{{ s.legacy ? t('from_old_system') : (s.taken_by || '—') }}<span v-if="s.submitted_at && !s.legacy" class="block text-slate-500">{{ stamp(s.submitted_at, page.props.locale) }}</span><span v-if="s.updated_by" class="block text-orange-700 dark:text-orange-300">{{ t('updated_by', { name: s.updated_by, at: stamp(s.updated_at, page.props.locale) }) }}</span><span v-if="s.late_submission" class="badge" :class="SOFT.late">{{ t('late_submitted') }}</span></td>
                        <td><Link class="btn !h-8" :href="route('attendance.index', { class: s.class_id, date: s.date, ...(s.period_id ? { period: s.period_id } : {}) })">{{ t('open') }}</Link></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <nav v-if="sessions.last_page > 1" class="mt-3 flex items-center justify-between text-sm">
            <Link v-if="sessions.prev_page_url" class="btn" :href="sessions.prev_page_url" preserve-scroll>← {{ t('previous') }}</Link><span v-else />
            <span>{{ t('page_of', { page: n(sessions.current_page), pages: n(sessions.last_page) }) }}</span>
            <Link v-if="sessions.next_page_url" class="btn" :href="sessions.next_page_url" preserve-scroll>{{ t('next') }} →</Link><span v-else />
        </nav>
    </Shell>
</template>
