<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import LanguageSwitch from '@/Components/StudentModule/LanguageSwitch.vue'
import { Head, router } from '@inertiajs/vue3'
import { computed, reactive, ref, watch } from 'vue'
import { useT } from '@/composables/useT'

const props = defineProps({
    years: { type: Array, default: () => [] },
    filters: { type: Object, required: true },
    classes: { type: Array, default: () => [] },
    targets: { type: Array, default: () => [] },
    students: { type: Array, default: () => [] },
    recent: { type: Array, default: () => [] },
    flash: { type: Object, default: () => ({}) },
    errors: { type: Object, default: () => ({}) },
})

const t = useT()

const f = reactive({ from: props.filters.from, to: props.filters.to, class_id: props.filters.class_id || '' })

// one decision per student: '', promote, repeat, drop
const rows = reactive({})
watch(() => props.students, (list) => {
    Object.keys(rows).forEach((k) => delete rows[k])
    list.forEach((s) => { rows[s.id] = { action: '', class_id: '', roll_number: '' } })
}, { immediate: true })

const load = () => router.get(route('sm.promotion.manual'), { ...f, class_id: f.class_id || undefined }, { preserveScroll: true })

const chosen = computed(() => props.students.filter((s) => rows[s.id]?.action))
const busy = ref(false)
const bulkClass = ref('')

const applyAll = () => {
    props.students.filter((s) => s.status === 'active').forEach((s) => {
        rows[s.id].action = 'promote'
        rows[s.id].class_id = bulkClass.value
    })
}

const submit = () => {
    busy.value = true
    router.post(route('sm.promotion.manual.apply'), {
        from: f.from, to: f.to,
        decisions: chosen.value.map((s) => ({
            enrollment_id: s.id, action: rows[s.id].action,
            class_id: rows[s.id].class_id || null, roll_number: rows[s.id].roll_number || null,
        })),
    }, { preserveScroll: true, onFinish: () => { busy.value = false }, onSuccess: () => load() })
}

const input = 'h-9 w-full rounded-md border border-slate-300 bg-white px-3 text-sm focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-600/20 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100'
const label = 'mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400'
</script>

<template>
    <Head :title="t('promo_manual')" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-slate-50 text-slate-800 dark:bg-slate-950 dark:text-slate-200">
            <div class="mx-auto max-w-6xl px-4 py-5 sm:px-6 lg:py-7">

                <nav class="mb-2 text-xs text-slate-500 dark:text-slate-400" aria-label="Breadcrumb">{{ t('module') }} / <span class="font-medium text-slate-700 dark:text-slate-200">{{ t('promo_manual') }}</span></nav>

                <header class="mb-5 flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 pb-4 dark:border-slate-800">
                    <div>
                        <h1 class="text-xl font-semibold text-slate-900 dark:text-white">{{ t('promo_manual') }}</h1>
                        <p class="mt-0.5 max-w-3xl text-sm text-slate-500 dark:text-slate-400">{{ t('promo_manual_intro') }}</p>
                    </div>
                    <LanguageSwitch />
                </header>

                <div v-if="flash.success" class="mb-4 rounded-md border border-l-4 border-slate-200 border-l-green-600 bg-white px-4 py-3 text-sm dark:border-slate-700 dark:border-l-green-500 dark:bg-slate-900" role="status">{{ flash.success }}</div>
                <div v-if="errors.decisions" class="mb-4 rounded-md border border-l-4 border-slate-200 border-l-red-600 bg-white px-4 py-3 text-sm dark:border-slate-700 dark:border-l-red-500 dark:bg-slate-900" role="alert">{{ errors.decisions }}</div>

                <form class="rounded-md border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900" @submit.prevent="load">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-4">
                        <div>
                            <label :class="label" for="from">{{ t('from_session') }}</label>
                            <select id="from" v-model="f.from" :class="input" @change="f.to = String(Number(f.from) + 1); f.class_id = ''; load()"><option v-for="y in years" :key="y" :value="y">{{ y }}</option></select>
                        </div>
                        <div>
                            <label :class="label" for="to">{{ t('to_session') }}</label>
                            <input id="to" v-model="f.to" type="text" inputmode="numeric" maxlength="4" :class="input" @change="load" />
                        </div>
                        <div class="sm:col-span-2">
                            <label :class="label" for="class_id">{{ t('class_section') }}</label>
                            <select id="class_id" v-model="f.class_id" :class="input" @change="load"><option value="">{{ t('select') }}</option><option v-for="c in classes" :key="c.id" :value="String(c.id)">{{ c.label }}</option></select>
                        </div>
                    </div>
                </form>

                <p v-if="!f.class_id" class="mt-5 rounded-md border border-slate-200 bg-white px-4 py-10 text-center text-sm text-slate-500 dark:border-slate-800 dark:bg-slate-900">{{ t('choose_class_to_load') }}</p>

                <section v-else class="mt-5 overflow-hidden rounded-md border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex flex-wrap items-end gap-2 border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                        <div class="min-w-[12rem] flex-1">
                            <label :class="label" for="bulk">{{ t('promote_all_to') }}</label>
                            <select id="bulk" v-model="bulkClass" :class="input"><option value="">{{ t('select') }}</option><option v-for="c in targets" :key="c.id" :value="String(c.id)">{{ c.label }}</option></select>
                        </div>
                        <button type="button" :disabled="!bulkClass" class="h-9 rounded-md border border-slate-300 bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200" @click="applyAll">{{ t('apply_to_all') }}</button>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[760px] text-sm">
                            <thead class="bg-slate-100 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                <tr><th class="px-3 py-2">{{ t('roll') }}</th><th class="px-3 py-2">{{ t('name_en') }}</th><th class="px-3 py-2">{{ t('action') }}</th><th class="px-3 py-2">{{ t('target_class', { to: f.to }) }}</th><th class="px-3 py-2">{{ t('new_roll') }}</th></tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                                <tr v-for="s in students" :key="s.id" :class="s.status !== 'active' ? 'opacity-60' : ''">
                                    <td class="px-3 py-1.5 tabular-nums">{{ s.roll || '—' }}</td>
                                    <td class="px-3 py-1.5">{{ s.name }} <span class="font-mono text-xs text-slate-500">{{ s.code }}</span><span v-if="s.status !== 'active'" class="ml-2 rounded border border-slate-300 px-1.5 text-xs">{{ s.status_label }}</span></td>
                                    <td class="px-3 py-1.5">
                                        <select v-model="rows[s.id].action" :disabled="s.status !== 'active'" class="h-8 w-full rounded-md border border-slate-300 bg-white px-2 text-sm disabled:bg-slate-100 dark:border-slate-700 dark:bg-slate-900" :aria-label="s.name">
                                            <option value="">{{ t('no_change') }}</option>
                                            <option value="promote">{{ t('do_promote') }}</option>
                                            <option value="repeat">{{ t('do_repeat') }}</option>
                                            <option value="drop">{{ t('do_drop') }}</option>
                                            <option value="transfer">{{ t('do_transfer') }}</option>
                                        </select>
                                    </td>
                                    <td class="px-3 py-1.5">
                                        <select v-if="['promote', 'repeat'].includes(rows[s.id].action)" v-model="rows[s.id].class_id" class="h-8 w-full rounded-md border border-slate-300 bg-white px-2 text-sm dark:border-slate-700 dark:bg-slate-900">
                                            <option value="">{{ rows[s.id].action === 'repeat' ? t('do_repeat') : t('select') }}</option>
                                            <option v-for="c in targets" :key="c.id" :value="String(c.id)">{{ c.label }}</option>
                                        </select>
                                    </td>
                                    <td class="px-3 py-1.5">
                                        <input v-if="['promote', 'repeat'].includes(rows[s.id].action)" v-model="rows[s.id].roll_number" type="text" inputmode="numeric" maxlength="50" class="h-8 w-20 rounded-md border border-slate-300 bg-white px-2 text-sm dark:border-slate-700 dark:bg-slate-900" />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="flex justify-end border-t border-slate-200 px-4 py-3 dark:border-slate-800">
                        <button type="button" :disabled="!chosen.length || busy" class="h-9 rounded-md bg-blue-700 px-5 text-sm font-semibold text-white hover:bg-blue-800 disabled:opacity-50" @click="submit">{{ t('apply_decisions', { count: chosen.length }) }}</button>
                    </div>
                </section>

                <section v-if="recent.length" class="mt-5 overflow-hidden rounded-md border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="border-b border-slate-200 px-4 py-2.5 text-sm font-semibold dark:border-slate-800">{{ t('recent_promotions') }}</h2>
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                            <tr v-for="r in recent" :key="r.id">
                                <td class="px-4 py-2 tabular-nums">{{ r.from }} → {{ r.to }}</td>
                                <td class="px-4 py-2 text-xs uppercase text-slate-500">{{ r.mode }}</td>
                                <td class="px-4 py-2 text-xs">{{ r.promoted }} {{ t('promoted_word') }}<template v-if="r.repeated"> · {{ r.repeated }} {{ t('repeated_word') }}</template><template v-if="r.dropped"> · {{ r.dropped }} {{ t('dropped_word') }}</template></td>
                                <td class="px-4 py-2 text-xs text-slate-500">{{ r.by }} · {{ r.at }}</td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
