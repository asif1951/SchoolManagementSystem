<script setup>
/** On the student profile: the attendance of the chosen year, by month and subject, with recent days and leave. */
import { Link, usePage } from '@inertiajs/vue3'
import { computed, onMounted, ref, watch } from 'vue'
import { useT } from '@/composables/useT'
import { SOFT, day, num, useCalendarMode } from '@/lib/attendance'

const props = defineProps({ enrollments: { type: Array, required: true } })
const t = useT('attendance')
const page = usePage()
const mode = useCalendarMode()
const n = (v) => num(v, page.props.locale)
const allowed = computed(() => (page.props.auth?.abilities ?? []).some((a) => a === '*' || a.startsWith('attendance.view')))
const current = ref(props.enrollments[0]?.id ?? null)
const data = ref(null)
const loading = ref(false)
const load = async () => {
    if (!allowed.value || !current.value) return
    loading.value = true
    try { data.value = (await window.axios.get(route('attendance.student', current.value))).data } catch { data.value = null } finally { loading.value = false }
}
onMounted(load)
watch(current, load)
const low = computed(() => data.value?.summary?.percent !== null && data.value?.summary?.percent < data.value?.threshold)
</script>

<template>
    <section v-if="allowed && (data || loading)" class="mb-5 rounded-md border border-slate-200 bg-white p-4 text-sm text-slate-800 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-200">
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold">{{ t('profile_title') }}</h2>
            <select v-if="enrollments.length > 1" v-model="current" class="field !h-8 !w-auto text-xs" :aria-label="t('year')"><option v-for="e in enrollments" :key="e.id" :value="e.id">{{ e.session }}</option></select>
        </div>
        <p v-if="loading" class="text-slate-500">{{ t('loading') }}</p>
        <p v-else-if="!data?.summary" class="text-slate-500">{{ t('no_attendance') }}</p>
        <template v-else>
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-2xl font-semibold" :class="low ? 'text-red-700 dark:text-red-300' : ''">{{ data.summary.percent === null ? '—' : `${n(data.summary.percent)}%` }}</span>
                <span v-if="low" class="badge" :class="SOFT.absent">{{ t('below', { n: n(data.threshold) }) }}</span>
                <span v-for="k in ['present', 'absent', 'late', 'leave']" :key="k" class="badge" :class="SOFT[k]">{{ t(`status.${k}`) }} {{ n(data.summary[k]) }}</span>
                <span class="badge bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-100">{{ t('total') }} {{ n(data.summary.total) }}</span>
            </div>
            <div class="mt-3 grid gap-4 md:grid-cols-2">
                <div><h3 class="field-label">{{ t('months') }}</h3>
                    <ul><li v-for="m in data.months" :key="m.month" class="flex justify-between border-t border-slate-100 py-1 dark:border-slate-800"><span>{{ n(m.month) }}</span><span class="tabular-nums">{{ n(m.present + m.late) }}/{{ n(m.total) }} · {{ n(m.percent) }}%</span></li></ul></div>
                <div v-if="data.subjects.length"><h3 class="field-label">{{ t('subject_summary') }}</h3>
                    <ul><li v-for="s in data.subjects" :key="s.name" class="flex justify-between border-t border-slate-100 py-1 dark:border-slate-800"><span>{{ s.name }}</span><span class="tabular-nums">{{ n(s.present + s.late) }}/{{ n(s.total) }} · {{ n(s.percent) }}%</span></li></ul></div>
                <div><h3 class="field-label">{{ t('recent_days') }}</h3>
                    <ul class="max-h-56 overflow-y-auto"><li v-for="d in data.days" :key="d.date" class="flex justify-between gap-2 border-t border-slate-100 py-1 dark:border-slate-800"><span>{{ day(d.date, mode) }}</span><span><span v-if="d.total" class="mr-1 text-xs text-slate-500">{{ n(d.present + d.late) }}/{{ n(d.total) }}</span><span class="badge" :class="SOFT[d.status]">{{ t(`status.${d.status}`) }}</span></span></li></ul></div>
                <div v-if="data.leaves.length"><h3 class="field-label">{{ t('leaves') }}</h3>
                    <ul><li v-for="l in data.leaves" :key="l.id" class="border-t border-slate-100 py-1 dark:border-slate-800">{{ l.type }} · {{ day(l.from, mode) }} – {{ day(l.to, mode) }} · {{ t(`status_leave.${l.status}`) }}</li></ul>
                    <Link :href="route('attendance.leave', { student: current })" class="text-xs underline">{{ t('history_student') }}</Link></div>
            </div>
        </template>
    </section>
</template>
