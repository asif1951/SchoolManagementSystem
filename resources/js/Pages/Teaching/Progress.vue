<script setup>
import Shell from '@/Components/Teaching/Shell.vue'
import Bar from '@/Components/Teaching/Bar.vue'
import Chip from '@/Components/Teaching/Chip.vue'
import Dialog from '@/Components/Teaching/Dialog.vue'
import { Link, router } from '@inertiajs/vue3'
import { reactive, ref } from 'vue'
import { useT } from '@/composables/useT'

const props = defineProps({ years: Array, session: String, filters: Object, by: String, summary: Object, groups: Array, plans: Object, delayed: Array, seesAll: Boolean, teachers: Array, canReview: Boolean, flash: Object })
const t = useT('teaching')
const f = reactive({ session: props.session, ...props.filters, by: props.by })
const load = () => router.get(route('teaching.progress'), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveScroll: true, preserveState: true, replace: true })
const pickHealth = (h) => { f.health = f.health === h ? null : h; load() }

const reviewing = ref(null)
const body = ref('')
const send = () => router.post(route('teaching.plans.review', reviewing.value.id), { body: body.value }, { preserveScroll: true, onSuccess: () => { reviewing.value = null } })
const tone = { green: 'border-green-600', amber: 'border-amber-500', red: 'border-red-600', none: 'border-slate-400' }
</script>

<template>
    <Shell :title="t('nav.progress')" :subtitle="t('hint.progress')">
        <section class="panel mb-4 grid grid-cols-2 gap-3 p-4 sm:grid-cols-4">
            <div><label class="field-label" for="g-year">{{ t('session') }}</label><select id="g-year" v-model="f.session" class="field" @change="load"><option v-for="y in years" :key="y" :value="y">{{ y }}</option></select></div>
            <div v-if="seesAll" class="col-span-1 sm:col-span-2"><label class="field-label" for="g-teacher">{{ t('teacher') }}</label><select id="g-teacher" v-model="f.teacher" class="field" @change="load"><option :value="null">{{ t('all') }}</option><option v-for="x in teachers" :key="x.id" :value="x.id">{{ x.name }}</option></select></div>
        </section>

        <section class="panel mb-4 p-4">
            <Bar :done="summary.done" :expected="summary.expected" :health="summary.done - summary.expected >= -5 ? 'green' : summary.done - summary.expected >= -15 ? 'amber' : 'red'" />
            <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                <button v-for="h in ['green', 'amber', 'red', 'none']" :key="h" type="button" class="rounded-md border-2 border-l-8 px-3 py-2 text-left" :class="[tone[h], f.health === h ? 'bg-brand-50' : 'bg-white dark:bg-slate-900']" :aria-pressed="f.health === h" @click="pickHealth(h)">
                    <div class="text-2xl font-semibold tabular-nums">{{ summary[h] }}</div><div class="text-xs text-slate-600 dark:text-slate-300">{{ t(`status.${h}`) }}</div>
                </button>
            </div>
        </section>

        <section class="panel mb-4 overflow-hidden">
            <h2 class="border-b border-slate-200 px-4 py-2 text-sm font-semibold dark:border-slate-800">{{ t('progress.plans') }}</h2>
            <div class="overflow-x-auto">
                <table class="data-table min-w-[820px]">
                    <thead><tr><th>{{ t('subject') }} · {{ t('class') }}</th><th v-if="seesAll">{{ t('teacher') }}</th><th class="w-56">{{ t('done') }} / {{ t('expected') }}</th><th>{{ t('status_label') }}</th><th>{{ t('status.delayed') }}</th><th>{{ t('finish_by') }}</th><th v-if="canReview" class="num">{{ t('actions') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="p in plans.data" :key="p.id">
                            <td><Link :href="route('teaching.plans.show', p.id)" class="font-medium hover:text-brand-700 hover:underline">{{ p.subject }} · {{ p.class }}</Link></td>
                            <td v-if="seesAll">{{ p.teacher }}</td>
                            <td><Bar :done="p.done" :expected="p.health === 'none' ? null : p.expected" :health="p.health" /></td>
                            <td><Chip :value="p.health" :gap="p.gap" /></td>
                            <td class="tabular-nums">{{ p.delayed || '—' }}</td>
                            <td class="whitespace-nowrap">{{ p.finish || '—' }}</td>
                            <td v-if="canReview" class="num"><button type="button" class="text-sm font-medium text-brand-700 hover:underline" @click="body = ''; reviewing = p">{{ t('progress.comment') }}</button></td>
                        </tr>
                        <tr v-if="!plans.data.length"><td colspan="7" class="px-4 py-8 text-center text-slate-500">{{ t('plans.empty') }}</td></tr>
                    </tbody>
                </table>
            </div>
            <nav v-if="plans.prev || plans.next" class="flex justify-center gap-3 p-3"><Link v-if="plans.prev" :href="plans.prev" class="btn" preserve-scroll>← {{ t('previous') }}</Link><Link v-if="plans.next" :href="plans.next" class="btn" preserve-scroll>{{ t('next') }} →</Link></nav>
        </section>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <section class="panel overflow-hidden">
                <h2 class="border-b border-slate-200 px-4 py-2 text-sm font-semibold dark:border-slate-800">{{ t('progress.delayed') }}</h2>
                <ul class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                    <li v-for="d in delayed" :key="d.id" class="px-4 py-2"><Link :href="route('teaching.plans.show', d.plan_id)" class="font-medium hover:underline">{{ d.chapter }}</Link><div class="text-xs text-slate-500">{{ d.subject }} · {{ d.class }} · {{ d.teacher }} · <b class="text-red-700">{{ t('progress.overdue', { days: d.days }) }}</b> · {{ Math.round(d.progress) }}%</div></li>
                    <li v-if="!delayed.length" class="px-4 py-6 text-center text-slate-500">{{ t('progress.none_delayed') }}</li>
                </ul>
            </section>
            <section class="panel overflow-hidden">
                <div class="flex items-center justify-between border-b border-slate-200 px-4 py-2 dark:border-slate-800">
                    <h2 class="text-sm font-semibold">{{ t('progress.completion') }}</h2>
                    <select v-model="f.by" class="rounded border-slate-200 py-0.5 text-xs" :aria-label="t('progress.by')" @change="load"><option value="subject">{{ t('subject') }}</option><option value="class">{{ t('class') }}</option></select>
                </div>
                <ul class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                    <li v-for="g in groups" :key="g.id" class="px-4 py-2"><div class="mb-1 flex justify-between"><span class="font-medium">{{ g.name }}</span><span class="text-xs text-slate-500">{{ g.plans }}</span></div><Bar :done="g.done" :expected="g.expected" :health="g.gap >= -5 ? 'green' : g.gap >= -15 ? 'amber' : 'red'" /></li>
                </ul>
            </section>
        </div>

        <Dialog v-if="reviewing" :title="`${t('progress.comment')} — ${reviewing.teacher}`" @close="reviewing = null">
            <p class="mb-2 text-slate-500">{{ reviewing.subject }} · {{ reviewing.class }}</p>
            <textarea v-model="body" rows="4" maxlength="1000" class="field" :aria-label="t('plan.write_review')" :placeholder="t('plan.write_review')"></textarea>
            <div class="mt-3 flex justify-end gap-2"><button type="button" class="btn" @click="reviewing = null">{{ t('cancel') }}</button><button type="button" class="btn btn-primary" :disabled="!body.trim()" @click="send">{{ t('plan.send') }}</button></div>
        </Dialog>
    </Shell>
</template>
