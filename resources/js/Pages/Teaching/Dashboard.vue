<script setup>
/** The school at a glance (Head Teacher and Admin). Until the module is set up, the steps to do it come first. */
import Shell from '@/Components/Teaching/Shell.vue'
import Bar from '@/Components/Teaching/Bar.vue'
import Chip from '@/Components/Teaching/Chip.vue'
import { Link, router } from '@inertiajs/vue3'
import { computed } from 'vue'
import { useT } from '@/composables/useT'

const props = defineProps({
    years: Array, session: String, today: String, unread: Array, flash: Object, setup: Array,
    summary: Object, behind: Array, notLogged: Array, notLoggedCount: Number, groups: Array,
})
const t = useT('teaching')
const go = (session) => router.get(route('teaching.dashboard'), { session }, { preserveScroll: true })
const read = (id) => router.post(route('teaching.notices.read'), { id }, { preserveScroll: true })
const openNotice = (n) => router.post(route('teaching.notices.read'), { id: n.id }, { onSuccess: () => router.visit(n.url) })
const health = (s) => (s.done - s.expected >= -5 ? 'green' : s.done - s.expected >= -15 ? 'amber' : 'red')
const left = computed(() => props.setup.filter((s) => !s.done).length)
const nextStep = computed(() => props.setup.find((s) => !s.done)?.key)
</script>

<template>
    <Shell :title="t('menu.overview')" :subtitle="t('hint.overview')">
        <template #actions>
            <select class="field w-28" :value="session" :aria-label="t('session')" @change="go($event.target.value)"><option v-for="y in years" :key="y" :value="y">{{ y }}</option></select>
        </template>

        <section v-if="left" class="panel mb-4 border-2 !border-brand-600 p-4">
            <h2 class="text-base font-semibold">{{ t('setup.title') }} <span class="font-normal text-slate-500">· {{ t('setup.progress', { done: setup.length - left, total: setup.length }) }}</span></h2>
            <ol class="mt-3 grid grid-cols-1 gap-2 md:grid-cols-2">
                <li v-for="(s, i) in setup" :key="s.key" class="flex items-start gap-3 rounded-md border p-3" :class="s.done ? 'border-green-200 bg-green-50 text-slate-800' : s.key === nextStep ? 'border-brand-600 bg-brand-50 text-slate-800' : 'border-slate-200 text-slate-800 dark:border-slate-700 dark:text-slate-100'">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-sm font-bold" :class="s.done ? 'bg-green-600 text-white' : 'bg-slate-200 text-slate-700'">{{ s.done ? '✓' : i + 1 }}</span>
                    <span class="min-w-0 flex-1"><span class="block font-semibold">{{ t(`setup.${s.key}`) }}</span><span class="text-xs opacity-80">{{ t(`setup.${s.key}_help`) }}</span></span>
                    <Link v-if="s.url && !s.done" :href="s.url" class="btn shrink-0" :class="s.key === nextStep ? 'btn-primary' : ''">{{ t('open') }}</Link>
                </li>
            </ol>
        </section>

        <section v-if="unread.length" class="panel mb-4 border-l-4 !border-l-brand-700 p-3" :aria-label="t('dash.notices')">
            <div class="mb-1 flex justify-between"><h2 class="text-sm font-semibold">{{ t('dash.notices') }} ({{ unread.length }})</h2><button type="button" class="text-xs text-brand-700 underline" @click="read(null)">{{ t('dash.read_all') }}</button></div>
            <ul class="space-y-1 text-sm"><li v-for="n in unread" :key="n.id"><button type="button" class="text-left hover:underline" @click="openNotice(n)">{{ n.text }} <span class="text-xs text-slate-400">{{ n.at }}</span></button></li></ul>
        </section>

        <template v-if="summary.plans">
            <Link v-if="summary.submitted" :href="route('teaching.plans', { session, status: 'submitted' })" class="mb-4 block rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900">{{ t('dash.to_approve', { count: summary.submitted }) }} →</Link>

            <section class="panel mb-4 p-4">
                <h2 class="mb-2 text-sm font-semibold">{{ t('dash.completion') }}</h2>
                <Bar :done="summary.done" :expected="summary.expected" :health="health(summary)" />
                <div class="mt-3 grid grid-cols-3 gap-3 text-center">
                    <Link :href="route('teaching.progress', { session, health: 'red' })" class="rounded-md border border-slate-200 p-3 hover:border-brand-500 dark:border-slate-800"><div class="text-2xl font-semibold tabular-nums text-red-700">{{ summary.red + summary.amber }}</div><div class="text-xs text-slate-600 dark:text-slate-300">{{ t('dash.plans_behind') }}</div></Link>
                    <Link :href="route('teaching.reports', { report: 'delayed', session })" class="rounded-md border border-slate-200 p-3 hover:border-brand-500 dark:border-slate-800"><div class="text-2xl font-semibold tabular-nums">{{ summary.delayed }}</div><div class="text-xs text-slate-600 dark:text-slate-300">{{ t('progress.delayed') }}</div></Link>
                    <Link :href="route('teaching.reports', { report: 'pending', from: today, to: today })" class="rounded-md border border-slate-200 p-3 hover:border-brand-500 dark:border-slate-800"><div class="text-2xl font-semibold tabular-nums">{{ notLoggedCount }}</div><div class="text-xs text-slate-600 dark:text-slate-300">{{ t('dash.not_logged') }}</div></Link>
                </div>
            </section>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <section class="panel overflow-hidden">
                    <h2 class="border-b border-slate-200 px-4 py-2 text-sm font-semibold dark:border-slate-800">{{ t('dash.behind') }}</h2>
                    <ul class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                        <li v-for="p in behind" :key="p.id" class="px-4 py-2"><Link :href="route('teaching.plans.show', p.id)" class="flex justify-between gap-2 hover:underline"><span class="min-w-0"><span class="font-medium">{{ p.teacher }}</span> · {{ p.subject }} · {{ p.class }}</span><Chip :value="p.health" :gap="p.gap" /></Link></li>
                        <li v-if="!behind.length" class="px-4 py-6 text-center text-slate-500">{{ t('dash.nobody_behind') }}</li>
                    </ul>
                </section>
                <section class="panel overflow-hidden">
                    <h2 class="border-b border-slate-200 px-4 py-2 text-sm font-semibold dark:border-slate-800">{{ t('dash.not_logged') }}</h2>
                    <ul class="max-h-80 divide-y divide-slate-100 overflow-auto text-sm dark:divide-slate-800">
                        <li v-for="p in notLogged" :key="p.teacher_id" class="px-4 py-2"><Link :href="route('teaching.log', { teacher: p.teacher_id, date: today })" class="flex justify-between hover:underline"><span>{{ p.name }}</span><span class="tabular-nums text-amber-700">{{ p.missing }} / {{ p.due }}</span></Link></li>
                        <li v-if="!notLogged.length" class="px-4 py-6 text-center text-slate-500">{{ t('dash.all_logged') }}</li>
                    </ul>
                </section>
            </div>
            <section v-if="groups.length" class="panel mt-4 p-4">
                <h2 class="mb-3 text-sm font-semibold">{{ t('progress.completion') }}</h2>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3"><Link v-for="g in groups" :key="g.id" :href="route('teaching.plans', { session, class: g.id })" class="block"><div class="text-sm font-medium">{{ g.name }}</div><Bar :done="g.done" :expected="g.expected" :health="health(g)" /></Link></div>
            </section>
        </template>
        <p v-else class="panel px-4 py-10 text-center text-sm text-slate-500">{{ t('dash.no_data') }}</p>
    </Shell>
</template>
