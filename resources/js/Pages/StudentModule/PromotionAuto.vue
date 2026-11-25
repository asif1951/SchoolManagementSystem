<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import LanguageSwitch from '@/Components/StudentModule/LanguageSwitch.vue'
import TaskProgress from '@/Components/StudentModule/TaskProgress.vue'
import { Head, router } from '@inertiajs/vue3'
import { computed, reactive, ref } from 'vue'
import { useT } from '@/composables/useT'

const props = defineProps({
    years: { type: Array, default: () => [] },
    classNames: { type: Array, default: () => [] },
    filters: { type: Object, required: true },
    targetHasClasses: { type: Boolean, default: false },
    previewRequested: { type: Boolean, default: false },
    plan: { type: Object, default: null },
    task: { type: Number, default: null },
    recent: { type: Array, default: () => [] },
    flash: { type: Object, default: () => ({}) },
})

const t = useT()

const f = reactive({
    from: props.filters.from, to: props.filters.to, class_name: props.filters.class_name || '',
    roll_mode: props.filters.roll_mode || 'sequential', exclude_failed: !!props.filters.exclude_failed, graduate_last: !!props.filters.graduate_last,
})

const query = () => {
    const q = { ...f, preview: 1 }
    if (!q.class_name) delete q.class_name
    if (!q.exclude_failed) delete q.exclude_failed
    if (!q.graduate_last) delete q.graduate_last
    return q
}

const preview = () => router.get(route('sm.promotion.auto'), query(), { preserveScroll: true })
const prepare = () => router.post(route('sm.promotion.prepare'), query(), { preserveScroll: true })

const running = ref(props.task)
const busy = ref(false)
const finished = ref(null)

const confirm = () => {
    busy.value = true
    router.post(route('sm.promotion.run'), { ...f, exclude_failed: f.exclude_failed, graduate_last: f.graduate_last },
        { preserveScroll: true, onFinish: () => { busy.value = false }, onSuccess: (page) => { running.value = page.props.task } })
}

const done = (task) => { finished.value = task }

const toPromote = computed(() => (props.plan ? props.plan.totals.promote + props.plan.totals.graduate : 0))

const input = 'h-9 w-full rounded-md border border-slate-300 bg-white px-3 text-sm focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-600/20 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100'
const label = 'mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400'
const card = 'rounded-md border border-slate-200 bg-white px-4 py-3 dark:border-slate-800 dark:bg-slate-900'
</script>

<template>
    <Head :title="t('promo_auto')" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-slate-50 text-slate-800 dark:bg-slate-950 dark:text-slate-200">
            <div class="mx-auto max-w-5xl px-4 py-5 sm:px-6 lg:py-7">

                <nav class="mb-2 text-xs text-slate-500 dark:text-slate-400" aria-label="Breadcrumb">{{ t('module') }} / <span class="font-medium text-slate-700 dark:text-slate-200">{{ t('promo_auto') }}</span></nav>

                <header class="mb-5 flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 pb-4 dark:border-slate-800">
                    <div>
                        <h1 class="text-xl font-semibold text-slate-900 dark:text-white">{{ t('promo_auto') }}</h1>
                        <p class="mt-0.5 max-w-3xl text-sm text-slate-500 dark:text-slate-400">{{ t('promo_auto_intro') }}</p>
                    </div>
                    <LanguageSwitch />
                </header>

                <div v-if="flash.error" class="mb-4 rounded-md border border-l-4 border-slate-200 border-l-red-600 bg-white px-4 py-3 text-sm dark:border-slate-700 dark:border-l-red-500 dark:bg-slate-900" role="alert">{{ flash.error }}</div>

                <!-- choices -->
                <form class="rounded-md border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900" @submit.prevent="preview">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <label :class="label" for="from">{{ t('from_session') }}</label>
                            <select id="from" v-model="f.from" :class="input" @change="f.to = String(Number(f.from) + 1)"><option v-for="y in years" :key="y" :value="y">{{ y }}</option></select>
                        </div>
                        <div>
                            <label :class="label" for="to">{{ t('to_session') }}</label>
                            <input id="to" v-model="f.to" type="text" inputmode="numeric" maxlength="4" :class="input" />
                        </div>
                        <div>
                            <label :class="label" for="class_name">{{ t('class') }}</label>
                            <select id="class_name" v-model="f.class_name" :class="input"><option value="">{{ t('all_classes_option') }}</option><option v-for="c in classNames" :key="c" :value="c">{{ c }}</option></select>
                        </div>
                        <div>
                            <label :class="label" for="roll_mode">{{ t('roll_mode') }}</label>
                            <select id="roll_mode" v-model="f.roll_mode" :class="input">
                                <option value="sequential">{{ t('roll_sequential') }}</option>
                                <option value="keep">{{ t('roll_keep') }}</option>
                                <option value="result">{{ t('roll_result') }}</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                        <div class="flex flex-col gap-1.5 text-sm">
                            <label class="inline-flex items-center gap-2"><input v-model="f.exclude_failed" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-blue-700" />{{ t('exclude_failed') }}</label>
                            <label class="inline-flex items-center gap-2"><input v-model="f.graduate_last" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-blue-700" />{{ t('graduate_last') }}</label>
                        </div>
                        <button type="submit" class="h-9 rounded-md bg-blue-700 px-5 text-sm font-semibold text-white hover:bg-blue-800">{{ t('preview_button') }}</button>
                    </div>
                </form>

                <!-- the new session has no classes yet -->
                <div v-if="previewRequested && !targetHasClasses && Number(f.to) > Number(f.from)" class="mt-4 rounded-md border border-amber-300 bg-amber-50 p-4 text-sm dark:border-amber-800 dark:bg-amber-950/30">
                    <p class="text-amber-900 dark:text-amber-200">{{ t('no_classes_in_target', { to: f.to, from: f.from }) }}</p>
                    <button type="button" class="mt-2 h-9 rounded-md bg-amber-700 px-4 text-sm font-semibold text-white hover:bg-amber-800" @click="prepare">{{ t('create_classes', { to: f.to, from: f.from }) }}</button>
                </div>

                <!-- the preview -->
                <template v-if="plan">
                    <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div :class="card"><p class="text-xs text-slate-500">{{ t('will_promote') }}</p><p class="text-2xl font-semibold tabular-nums text-green-700 dark:text-green-400">{{ plan.totals.promote }}</p></div>
                        <div :class="card"><p class="text-xs text-slate-500">{{ t('left_out') }}</p><p class="text-2xl font-semibold tabular-nums">{{ plan.totals.skipped }}</p></div>
                        <div :class="card"><p class="text-xs text-slate-500">{{ t('blocked') }}</p><p class="text-2xl font-semibold tabular-nums" :class="plan.totals.blocked ? 'text-amber-700 dark:text-amber-400' : ''">{{ plan.totals.blocked }}</p></div>
                        <div :class="card"><p class="text-xs text-slate-500">{{ t('will_pass_out') }}</p><p class="text-2xl font-semibold tabular-nums">{{ plan.totals.graduate }}</p></div>
                    </div>

                    <section v-for="c in plan.classes" :key="c.from_class_id" class="mt-4 overflow-hidden rounded-md border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                        <details>
                            <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2 px-4 py-2.5 text-sm">
                                <span class="font-semibold">{{ c.from }} <span class="mx-1 text-slate-400">→</span> <span :class="c.to_class_id || c.final_class ? '' : 'text-amber-700 dark:text-amber-400'">{{ c.final_class ? '—' : (c.to || '?') }}</span></span>
                                <span class="text-xs text-slate-500">{{ c.counts.promote }} · {{ c.counts.skipped }} · {{ c.counts.blocked }}</span>
                            </summary>
                            <p v-if="c.blocked" class="border-t border-slate-200 bg-amber-50 px-4 py-2 text-sm text-amber-900 dark:border-slate-800 dark:bg-amber-950/30 dark:text-amber-200">{{ c.blocked }}</p>
                            <div class="max-h-80 overflow-auto border-t border-slate-200 dark:border-slate-800">
                                <table class="w-full min-w-[520px] text-sm">
                                    <thead class="sticky top-0 bg-slate-100 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                        <tr><th class="px-4 py-2">{{ t('name_en') }}</th><th class="px-4 py-2">{{ t('code') }}</th><th class="px-4 py-2">{{ t('old_roll') }}</th><th class="px-4 py-2">{{ t('new_roll') }}</th><th class="px-4 py-2">{{ t('action') }}</th></tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                                        <tr v-for="s in c.students" :key="s.enrollment_id">
                                            <td class="px-4 py-1.5">{{ s.name }}</td>
                                            <td class="px-4 py-1.5 font-mono text-xs">{{ s.code }}</td>
                                            <td class="px-4 py-1.5 tabular-nums">{{ s.roll || '—' }}</td>
                                            <td class="px-4 py-1.5 tabular-nums">{{ s.new_roll || '—' }}</td>
                                            <td class="px-4 py-1.5 text-xs" :class="s.action === 'promote' ? 'text-green-700 dark:text-green-400' : 'text-slate-500'">{{ s.action === 'promote' ? t('will_promote') : s.action === 'graduate' ? t('will_pass_out') : s.reason }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <p v-if="c.students_total > c.students.length" class="border-t border-slate-200 px-4 py-2 text-xs text-slate-500 dark:border-slate-800">{{ t('promo_sample', { shown: c.students.length, count: c.students_total }) }}</p>
                        </details>
                    </section>

                    <div class="mt-5 flex flex-wrap items-center justify-end gap-3">
                        <p v-if="!toPromote" class="text-sm text-slate-500">{{ t('nothing_to_promote') }}</p>
                        <button type="button" :disabled="!toPromote || busy || !!running" class="h-9 rounded-md bg-blue-700 px-5 text-sm font-semibold text-white hover:bg-blue-800 disabled:opacity-50" @click="confirm">{{ t('promote_n', { count: toPromote }) }}</button>
                    </div>
                </template>

                <!-- running / result -->
                <section v-if="running" class="mt-5 rounded-md border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="mb-2 text-sm font-semibold">{{ t('running_promotion') }}</h2>
                    <TaskProgress :task-id="running" @finished="done" />
                    <p v-if="finished && finished.status === 'done'" class="mt-3 text-sm text-green-700 dark:text-green-400">{{ t('promotion_done', { promoted: finished.summary.promoted, skipped: finished.summary.skipped }) }}</p>
                </section>

                <section v-if="recent.length" class="mt-5 overflow-hidden rounded-md border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="border-b border-slate-200 px-4 py-2.5 text-sm font-semibold dark:border-slate-800">{{ t('recent_promotions') }}</h2>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[560px] text-sm">
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                                <tr v-for="r in recent" :key="r.id">
                                    <td class="px-4 py-2 tabular-nums">{{ r.from }} → {{ r.to }}</td>
                                    <td class="px-4 py-2 text-xs uppercase text-slate-500">{{ r.mode }}</td>
                                    <td class="px-4 py-2 text-xs">{{ r.promoted }} {{ t('promoted_word') }}<template v-if="r.repeated"> · {{ r.repeated }} {{ t('repeated_word') }}</template><template v-if="r.dropped"> · {{ r.dropped }} {{ t('dropped_word') }}</template></td>
                                    <td class="px-4 py-2 text-xs text-slate-500">{{ r.by }} · {{ r.at }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
