<script setup>
import Shell from '@/Components/Teaching/Shell.vue'
import Bar from '@/Components/Teaching/Bar.vue'
import Chip from '@/Components/Teaching/Chip.vue'
import { Link, router } from '@inertiajs/vue3'
import { computed } from 'vue'
import { useT } from '@/composables/useT'

const props = defineProps({ mode: String, date: String, from: String, to: String, prev: String, next: String, plans: Array, teacher: Number, seesAll: Boolean, teachers: Array, editable: Array })
const t = useT('teaching')

const params = (more = {}) => ({ mode: props.mode, date: props.date, teacher: props.seesAll ? props.teacher ?? '' : undefined, ...more })
const go = (more) => router.get(route('teaching.period'), params(more), { preserveScroll: true, preserveState: true })
const fmt = (d, o) => new Date(`${d}T00:00:00Z`).toLocaleDateString(undefined, { timeZone: 'UTC', ...o })
const heading = computed(() => (props.mode === 'week' ? `${fmt(props.from, { day: 'numeric', month: 'short' })} – ${fmt(props.to, { day: 'numeric', month: 'short', year: 'numeric' })}` : fmt(props.from, { month: 'long', year: 'numeric' })))
const canEdit = (p) => props.editable.includes(p.id)
const setDate = (item, field, value) => { if (value && value !== item[field]) router.put(route('teaching.items.update', item.id), { [field]: value }, { preserveScroll: true, preserveState: true }) }
</script>

<template>
    <Shell :title="t('nav.period')" :subtitle="t('period.subtitle')">
        <template #actions>
            <a :href="route('teaching.period.print', params())" target="_blank" rel="noopener" class="btn">{{ t('period.print_pdf') }}</a>
        </template>

        <section class="panel mb-4 flex flex-wrap items-center gap-3 p-3">
            <div class="flex gap-1" role="tablist">
                <button v-for="m in ['week', 'month']" :key="m" type="button" role="tab" :aria-selected="mode === m" class="rounded px-3 py-1.5 text-sm font-medium" :class="mode === m ? 'bg-brand-700 text-white' : 'text-slate-600 hover:bg-slate-100'" @click="go({ mode: m })">{{ t(`period.${m}`) }}</button>
            </div>
            <button type="button" class="btn" :aria-label="t('previous')" @click="go({ date: prev })">←</button>
            <span class="min-w-[10rem] text-center text-sm font-semibold">{{ heading }}</span>
            <button type="button" class="btn" :aria-label="t('next')" @click="go({ date: next })">→</button>
            <select v-if="seesAll" class="field w-56" :value="teacher ?? ''" :aria-label="t('teacher')" @change="go({ teacher: $event.target.value })"><option value="">{{ t('all') }}</option><option v-for="x in teachers" :key="x.id" :value="x.id">{{ x.name }}</option></select>
        </section>

        <div v-if="!plans.length" class="panel px-4 py-10 text-center text-sm text-slate-500">
            <p>{{ t('period.empty') }}</p>
            <Link :href="route('teaching.plans')" class="btn btn-primary mt-3">{{ t('period.set_dates') }}</Link>
        </div>

        <section v-for="p in plans" :key="p.id" class="panel mb-3 overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-2 dark:border-slate-800">
                <Link :href="route('teaching.plans.show', p.id)" class="font-semibold hover:text-brand-700 hover:underline">{{ p.subject }} · {{ p.class }}<span v-if="seesAll" class="font-normal text-slate-500"> · {{ p.teacher }}</span></Link>
                <Chip :value="p.health" :gap="p.gap" />
            </div>
            <div class="overflow-x-auto">
                <table class="data-table min-w-[720px]">
                    <thead><tr><th>{{ t('chapter') }}</th><th>{{ t('from') }}</th><th>{{ t('to') }}</th><th>{{ t('periods') }}</th><th class="w-48">{{ t('period.by_end') }}</th><th>{{ t('status_label') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="i in p.items" :key="i.id">
                            <td class="font-medium text-slate-900 dark:text-white">{{ i.chapter }}</td>
                            <td><input type="date" class="field w-40" :value="i.starts_on" :disabled="!canEdit(p)" :aria-label="`${i.chapter} ${t('from')}`" @change="setDate(i, 'starts_on', $event.target.value)" /></td>
                            <td><input type="date" class="field w-40" :value="i.ends_on" :disabled="!canEdit(p)" :aria-label="`${i.chapter} ${t('to')}`" @change="setDate(i, 'ends_on', $event.target.value)" /></td>
                            <td class="tabular-nums">{{ i.periods }}</td>
                            <td><Bar :done="i.progress" :expected="i.expected" :health="i.status === 'delayed' ? 'red' : i.status === 'completed' ? 'green' : 'none'" /></td>
                            <td><Chip :value="i.status" /></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </Shell>
</template>
