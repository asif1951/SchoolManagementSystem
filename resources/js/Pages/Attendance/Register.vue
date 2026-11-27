<script setup>
import Shell from '@/Components/Attendance/Shell.vue'
import { router, usePage } from '@inertiajs/vue3'
import { computed, reactive } from 'vue'
import { useT } from '@/composables/useT'
import { SOFT, day, num, useCalendarMode } from '@/lib/attendance'

const props = defineProps({ year: String, years: Array, classes: Array, month: String, filters: Object, register: { type: Object, default: null } })
const t = useT('attendance')
const page = usePage()
const mode = useCalendarMode()
const n = (v) => num(v, page.props.locale)
const f = reactive({ year: props.year, month: props.month, ...props.filters })
const params = (extra = {}) => Object.fromEntries(Object.entries({ ...f, ...extra }).filter(([, v]) => v !== '' && v !== null))
const apply = (extra = {}) => router.get(route('attendance.register'), params(extra), { preserveState: true, preserveScroll: true })
const yearChanged = () => { f.class = ''; f.month = `${f.year}-01`; f.from = ''; f.to = ''; apply() }
const monthChanged = () => { f.year = f.month.slice(0, 4); f.from = ''; f.to = ''; apply() }
const canPrint = computed(() => (page.props.auth?.abilities ?? []).some((a) => a === '*' || a.startsWith('attendance.print')))
const offLetter = (d) => t(`short.${d.type === 'weekly' ? 'weekly' : d.type === 'closure' ? 'closure' : 'holiday'}`)
</script>

<template>
    <Shell :title="t('register_title')" :subtitle="t('register_sub')">
        <template #actions>
            <a v-if="register && canPrint" class="btn" target="_blank" rel="noopener" :href="route('attendance.print.register', params({ cal: mode }))">⎙ {{ t('print_register') }}</a>
        </template>
        <form class="mb-4 grid gap-2 rounded-lg border border-slate-200 bg-white p-3 sm:grid-cols-3 lg:grid-cols-5 dark:border-slate-800 dark:bg-slate-900" @submit.prevent>
            <label><span class="field-label">{{ t('year') }}</span><select v-model="f.year" class="field" @change="yearChanged"><option v-for="y in years" :key="y" :value="y">{{ n(y) }}</option></select></label>
            <label><span class="field-label">{{ t('class') }}</span><select v-model="f.class" class="field" @change="apply()"><option value="" disabled>{{ t('choose_class') }}</option><option v-for="c in classes" :key="c.id" :value="c.id">{{ c.label }}</option></select></label>
            <label><span class="field-label">{{ t('month') }}</span><input v-model="f.month" type="month" class="field" @change="monthChanged"></label>
            <label><span class="field-label">{{ t('from') }}</span><input v-model="f.from" type="date" class="field" :min="`${month}-01`" :max="`${month}-31`" @change="apply()"></label>
            <label><span class="field-label">{{ t('to') }}</span><input v-model="f.to" type="date" class="field" :min="`${month}-01`" :max="`${month}-31`" @change="apply()"></label>
        </form>

        <p v-if="!register" class="rounded-lg border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">{{ t('choose_class_first') }}</p>
        <template v-else>
            <p class="mb-2 text-sm">{{ day(register.from, mode) }} – {{ day(register.to, mode) }} · {{ t('working_days') }}: <b>{{ n(register.working_days) }}</b></p>
            <p v-if="!register.students.length" class="rounded-lg border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">{{ t('no_data') }}</p>
            <div v-else class="max-h-[70vh] overflow-auto rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <table class="w-full border-collapse text-xs">
                    <thead class="sticky top-0 z-10 bg-slate-100 dark:bg-slate-800">
                        <tr>
                            <th class="sticky left-0 z-20 min-w-[11rem] bg-slate-100 px-2 py-2 text-left dark:bg-slate-800">{{ t('col.roll') }} · {{ t('col.name') }}</th>
                            <th v-for="d in register.days" :key="d.date" class="min-w-[1.9rem] px-0.5 py-2" :class="!d.working && 'text-slate-400'" :title="`${day(d.date, mode)} ${d.reason ?? ''}`">{{ n(d.day) }}</th>
                            <th v-for="k in ['present', 'absent', 'late', 'leave']" :key="k" class="px-1.5">{{ t(`short.${k}`) }}</th><th class="px-2">%</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in register.students" :key="s.id" class="border-t border-slate-100 dark:border-slate-800">
                            <th class="sticky left-0 max-w-[14rem] truncate bg-white px-2 py-1.5 text-left font-medium dark:bg-slate-900"><span class="mr-2 tabular-nums text-slate-500">{{ s.roll ? n(s.roll) : '—' }}</span>{{ s.name }}</th>
                            <td v-for="d in register.days" :key="d.date" class="px-0.5 text-center" :class="!d.working && 'bg-slate-100 dark:bg-slate-800/60'">
                                <span v-if="s.cells[d.date]" class="inline-block min-w-[1.5rem] rounded px-0.5 py-0.5 font-semibold" :class="SOFT[s.cells[d.date].s]" :title="t(`status.${s.cells[d.date].s}`)">{{ s.cells[d.date].t ? n(s.cells[d.date].t) : t(`short.${s.cells[d.date].s}`) }}</span>
                                <span v-else-if="!d.working" class="text-slate-400" :title="d.reason">{{ offLetter(d) }}</span>
                            </td>
                            <td v-for="k in ['present', 'absent', 'late', 'leave']" :key="k" class="px-1.5 text-center tabular-nums">{{ n(s.sum[k]) }}</td>
                            <td class="px-2 text-right font-semibold tabular-nums">{{ s.sum.percent === null ? '—' : n(s.sum.percent) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-xs text-slate-500">{{ t('register_legend') }}</p>

            <section v-if="register.subjects.length" class="mt-5">
                <h2 class="mb-2 text-sm font-semibold">{{ t('subject_summary') }}</h2>
                <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <table class="data-table">
                        <thead><tr><th>{{ t('col.name') }}</th><th v-for="x in register.subjects" :key="x.id" class="text-right">{{ x.name }}</th></tr></thead>
                        <tbody><tr v-for="s in register.students" :key="s.id"><td class="whitespace-nowrap">{{ s.roll ? n(s.roll) : '—' }} · {{ s.name }}</td>
                            <td v-for="x in register.subjects" :key="x.id" class="whitespace-nowrap text-right tabular-nums"><template v-if="s.subjects[x.id]">{{ n(s.subjects[x.id].present + s.subjects[x.id].late) }}/{{ n(s.subjects[x.id].total) }} · {{ n(s.subjects[x.id].percent) }}%</template><template v-else>—</template></td></tr></tbody>
                    </table>
                </div>
            </section>

            <nav v-if="register.pages > 1" class="mt-3 flex items-center justify-between text-sm">
                <button type="button" class="btn" :disabled="register.page <= 1" @click="apply({ page: register.page - 1 })">← {{ t('previous') }}</button>
                <span>{{ t('page_of', { page: n(register.page), pages: n(register.pages) }) }}</span>
                <button type="button" class="btn" :disabled="register.page >= register.pages" @click="apply({ page: register.page + 1 })">{{ t('next') }} →</button>
            </nav>
        </template>
    </Shell>
</template>
