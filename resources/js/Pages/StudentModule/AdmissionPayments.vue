<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import LanguageSwitch from '@/Components/StudentModule/LanguageSwitch.vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { useT } from '@/composables/useT'

const props = defineProps({
    years: { type: Array, default: () => [] },
    classNames: { type: Array, default: () => [] },
    filters: { type: Object, required: true },
    summary: { type: Object, required: true },
    fees: { type: Array, default: () => [] },
    methods: { type: Object, default: () => ({}) },
    recent: { type: Array, default: () => [] },
    flash: { type: Object, default: () => ({}) },
    errors: { type: Object, default: () => ({}) },
})

const t = useT()

const f = reactive({ ...props.filters })
const money = (v) => Number(v || 0).toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const load = () => {
    const q = { ...f }
    Object.keys(q).forEach((k) => { if (!q[k]) delete q[k] })
    router.get(route('sm.admission-payments.index'), q, { preserveScroll: true, preserveState: true })
}

const exportUrl = (format) => route('sm.admission-payments.export', { ...f, format })

// ---- fees of the session
const feeForm = useForm({ session: props.filters.session, fees: Object.fromEntries(props.fees.map((x) => [x.class_name, x.amount || ''])) })
const saveFees = () => feeForm.post(route('sm.admission-payments.fees'), { preserveScroll: true })

// ---- collect a payment
const query = ref('')
const found = ref([])
const chosen = ref(null)
const pay = useForm({ enrollment_id: null, amount: '', method: 'cash', reference: '', note: '' })
let timer = null

watch(query, (q) => {
    clearTimeout(timer)
    if (q.trim().length < 2) { found.value = []; return }
    timer = setTimeout(async () => {
        const res = await fetch(route('sm.admission-payments.find', { q, session: f.session }), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        found.value = res.ok ? await res.json() : []
    }, 300)
})
onBeforeUnmount(() => clearTimeout(timer))

const pick = (s, due = '') => { chosen.value = s; pay.enrollment_id = s.id; pay.amount = due; found.value = []; query.value = '' }
const record = () => pay.post(route('sm.admission-payments.store'), { preserveScroll: true, onSuccess: () => { pay.reset('amount', 'reference', 'note'); chosen.value = null } })

// ---- void
const voiding = ref(null)
const voidReason = ref('')
const doVoid = () => router.post(route('sm.admission-payments.void', voiding.value), { reason: voidReason.value }, { preserveScroll: true, onSuccess: () => { voiding.value = null; voidReason.value = '' } })

const methodRows = computed(() => Object.entries(props.summary.by_method).map(([k, v]) => ({ key: k, name: props.methods[k] || k, ...v })))

const input = 'h-9 w-full rounded-md border border-slate-300 bg-white px-3 text-sm focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-600/20 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100'
const label = 'mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400'
const card = 'rounded-md border border-slate-200 bg-white px-4 py-3 dark:border-slate-800 dark:bg-slate-900'
const th = 'px-3 py-2'
</script>

<template>
    <Head :title="t('pay_title')" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-slate-50 text-slate-800 dark:bg-slate-950 dark:text-slate-200">
            <div class="mx-auto max-w-6xl px-4 py-5 sm:px-6 lg:py-7">

                <nav class="mb-2 text-xs text-slate-500 dark:text-slate-400" aria-label="Breadcrumb">{{ t('module') }} / <span class="font-medium text-slate-700 dark:text-slate-200">{{ t('pay_title') }}</span></nav>

                <header class="mb-5 flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 pb-4 dark:border-slate-800">
                    <h1 class="text-xl font-semibold text-slate-900 dark:text-white">{{ t('pay_title') }}</h1>
                    <div class="flex items-center gap-2">
                        <a :href="exportUrl('xlsx')" class="inline-flex h-9 items-center rounded-md border border-slate-300 bg-white px-3 text-sm font-medium dark:border-slate-700 dark:bg-slate-900">Excel</a>
                        <a :href="exportUrl('csv')" class="inline-flex h-9 items-center rounded-md border border-slate-300 bg-white px-3 text-sm font-medium dark:border-slate-700 dark:bg-slate-900">CSV</a>
                        <LanguageSwitch />
                    </div>
                </header>

                <div v-if="flash.success" class="mb-4 rounded-md border border-l-4 border-slate-200 border-l-green-600 bg-white px-4 py-3 text-sm dark:border-slate-700 dark:border-l-green-500 dark:bg-slate-900" role="status">{{ flash.success }}</div>
                <div v-if="flash.error" class="mb-4 rounded-md border border-l-4 border-slate-200 border-l-red-600 bg-white px-4 py-3 text-sm dark:border-slate-700 dark:border-l-red-500 dark:bg-slate-900" role="alert">{{ flash.error }}</div>

                <!-- filters -->
                <section class="rounded-md border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <div class="grid grid-cols-2 gap-3 md:grid-cols-5">
                        <div><label :class="label" for="s">{{ t('session') }}</label><select id="s" v-model="f.session" :class="input" @change="f.class_name = ''; load()"><option v-for="y in years" :key="y" :value="y">{{ y }}</option></select></div>
                        <div><label :class="label" for="c">{{ t('class') }}</label><select id="c" v-model="f.class_name" :class="input" @change="load"><option value="">{{ t('all_classes') }}</option><option v-for="c in classNames" :key="c" :value="c">{{ c }}</option></select></div>
                        <div><label :class="label" for="m">{{ t('pay_method') }}</label><select id="m" v-model="f.method" :class="input" @change="load"><option value="">{{ t('pay_all_methods') }}</option><option v-for="(n, k) in methods" :key="k" :value="k">{{ n }}</option></select></div>
                        <div><label :class="label" for="from">{{ t('pay_from') }}</label><input id="from" v-model="f.from" type="date" :class="input" @change="load" /></div>
                        <div><label :class="label" for="to">{{ t('pay_to') }}</label><input id="to" v-model="f.to" type="date" :class="input" @change="load" /></div>
                    </div>
                    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ t('pay_range_hint') }}</p>
                </section>

                <!-- figures -->
                <div class="mt-4 grid grid-cols-3 gap-3">
                    <div :class="card"><p class="text-xs text-slate-500">{{ t('pay_expected') }}</p><p class="text-xl font-semibold tabular-nums">৳ {{ money(summary.expected) }}</p></div>
                    <div :class="card"><p class="text-xs text-slate-500">{{ t('pay_collected') }}</p><p class="text-xl font-semibold tabular-nums text-green-700 dark:text-green-400">৳ {{ money(summary.collected) }}</p></div>
                    <div :class="card"><p class="text-xs text-slate-500">{{ t('pay_due') }}</p><p class="text-xl font-semibold tabular-nums" :class="summary.due > 0 ? 'text-red-700 dark:text-red-400' : ''">৳ {{ money(summary.due) }}</p></div>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <section class="overflow-hidden rounded-md border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                        <h2 class="border-b border-slate-200 px-4 py-2.5 text-sm font-semibold dark:border-slate-800">{{ t('pay_by_method') }}</h2>
                        <table class="w-full text-sm"><tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                            <tr v-for="m in methodRows" :key="m.key"><td class="px-4 py-2">{{ m.name }}</td><td class="px-4 py-2 text-right text-xs text-slate-500">{{ m.count }}</td><td class="px-4 py-2 text-right tabular-nums">৳ {{ money(m.total) }}</td></tr>
                            <tr v-if="!methodRows.length"><td class="px-4 py-4 text-center text-slate-400">—</td></tr>
                        </tbody></table>
                    </section>
                    <section class="overflow-hidden rounded-md border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                        <h2 class="border-b border-slate-200 px-4 py-2.5 text-sm font-semibold dark:border-slate-800">{{ t('pay_by_class') }}</h2>
                        <div class="overflow-x-auto"><table class="w-full min-w-[420px] text-sm">
                            <thead class="bg-slate-100 text-left text-xs uppercase tracking-wide text-slate-600 dark:bg-slate-800 dark:text-slate-300"><tr><th :class="th">{{ t('class') }}</th><th :class="[th, 'text-right']">{{ t('pay_students') }}</th><th :class="[th, 'text-right']">{{ t('pay_expected') }}</th><th :class="[th, 'text-right']">{{ t('pay_collected') }}</th><th :class="[th, 'text-right']">{{ t('pay_due') }}</th></tr></thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                                <tr v-for="c in summary.by_class" :key="c.class"><td :class="th">{{ c.class }}</td><td :class="[th, 'text-right tabular-nums']">{{ c.students }}</td><td :class="[th, 'text-right tabular-nums']">{{ money(c.expected) }}</td><td :class="[th, 'text-right tabular-nums']">{{ money(c.collected) }}</td><td :class="[th, 'text-right tabular-nums', c.due > 0 ? 'font-semibold text-red-700 dark:text-red-400' : '']">{{ money(c.due) }}</td></tr>
                            </tbody>
                        </table></div>
                    </section>
                </div>

                <!-- collect -->
                <section class="mt-4 rounded-md border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="text-sm font-semibold">{{ t('pay_collect') }}</h2>
                    <div v-if="!chosen" class="relative mt-3 max-w-md">
                        <label :class="label" for="find">{{ t('pay_find') }}</label>
                        <input id="find" v-model="query" type="search" autocomplete="off" :class="input" />
                        <ul v-if="found.length" class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-md border border-slate-200 bg-white shadow-lg dark:border-slate-700 dark:bg-slate-900">
                            <li v-for="s in found" :key="s.id"><button type="button" class="flex w-full justify-between px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800" @click="pick(s)"><span>{{ s.name }} <span class="text-xs text-slate-500">{{ s.class }}</span></span><span class="font-mono text-xs text-slate-500">{{ s.code }}</span></button></li>
                        </ul>
                    </div>
                    <form v-else class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-5" @submit.prevent="record">
                        <div class="sm:col-span-5 text-sm"><b>{{ chosen.name }}</b> <span class="font-mono text-xs text-slate-500">{{ chosen.code }}</span> · {{ chosen.class }} <button type="button" class="ml-2 text-xs text-blue-700 hover:underline" @click="chosen = null; pay.enrollment_id = null">×</button></div>
                        <div><label :class="label" for="amount">{{ t('pay_amount') }}</label><input id="amount" v-model="pay.amount" type="number" step="0.01" min="0.01" :class="input" required /></div>
                        <div><label :class="label" for="method">{{ t('pay_method') }}</label><select id="method" v-model="pay.method" :class="input"><option v-for="(n, k) in methods" :key="k" :value="k">{{ n }}</option></select></div>
                        <div><label :class="label" for="ref">{{ t('pay_reference') }}</label><input id="ref" v-model="pay.reference" type="text" maxlength="100" :class="input" /></div>
                        <div><label :class="label" for="note">{{ t('pay_note') }}</label><input id="note" v-model="pay.note" type="text" maxlength="255" :class="input" /></div>
                        <div class="flex items-end"><button type="submit" :disabled="pay.processing" class="h-9 w-full rounded-md bg-blue-700 px-4 text-sm font-semibold text-white hover:bg-blue-800 disabled:opacity-50">{{ t('pay_record') }}</button></div>
                        <p v-if="pay.errors.amount || pay.errors.method || pay.errors.student" class="text-xs text-red-600 sm:col-span-5" role="alert">{{ pay.errors.amount || pay.errors.method || pay.errors.student }}</p>
                    </form>
                </section>

                <!-- students with dues -->
                <section class="mt-4 overflow-hidden rounded-md border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="border-b border-slate-200 px-4 py-2.5 text-sm font-semibold dark:border-slate-800">{{ t('pay_with_dues') }} ({{ Number(summary.with_dues_count).toLocaleString() }})</h2>
                    <p v-if="summary.with_dues_count > summary.with_dues_shown" class="border-b border-slate-200 px-4 py-2 text-xs text-slate-500 dark:border-slate-800">{{ t('pay_top_dues', { shown: summary.with_dues_shown, count: Number(summary.with_dues_count).toLocaleString() }) }}</p>
                    <div class="max-h-96 overflow-auto"><table class="w-full min-w-[640px] text-sm">
                        <thead class="sticky top-0 bg-slate-100 text-left text-xs uppercase tracking-wide text-slate-600 dark:bg-slate-800 dark:text-slate-300"><tr><th :class="th">{{ t('name_en') }}</th><th :class="th">{{ t('class') }}</th><th :class="th">{{ t('mobile') }}</th><th :class="[th, 'text-right']">{{ t('pay_fee') }}</th><th :class="[th, 'text-right']">{{ t('pay_paid') }}</th><th :class="[th, 'text-right']">{{ t('pay_due') }}</th><th :class="th"></th></tr></thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                            <tr v-for="s in summary.with_dues" :key="s.id" class="bg-red-50/40 dark:bg-red-950/10">
                                <td :class="th">{{ s.name }} <span class="font-mono text-xs text-slate-500">{{ s.code }}</span></td><td :class="th">{{ s.class }}</td><td :class="th">{{ s.mobile || '—' }}</td>
                                <td :class="[th, 'text-right tabular-nums']">{{ money(s.fee) }}</td><td :class="[th, 'text-right tabular-nums']">{{ money(s.paid) }}</td><td :class="[th, 'text-right font-semibold tabular-nums text-red-700 dark:text-red-400']">{{ money(s.due) }}</td>
                                <td :class="th"><button type="button" class="rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium hover:bg-slate-100 dark:border-slate-700" @click="pick({ id: s.id, name: s.name, code: s.code, class: s.class }, s.due)">{{ t('pay_collect') }}</button></td>
                            </tr>
                        </tbody>
                    </table></div>
                </section>

                <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <section class="overflow-hidden rounded-md border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                        <h2 class="border-b border-slate-200 px-4 py-2.5 text-sm font-semibold dark:border-slate-800">{{ t('pay_recent') }}</h2>
                        <ul class="divide-y divide-slate-200 text-sm dark:divide-slate-800">
                            <li v-for="r in recent" :key="r.id" class="flex flex-wrap items-center justify-between gap-2 px-4 py-2" :class="r.void ? 'opacity-50 line-through' : ''">
                                <span><span class="font-mono text-xs">{{ r.receipt }}</span> · {{ r.student }}</span>
                                <span class="flex items-center gap-2"><span class="tabular-nums">৳ {{ money(r.amount) }}</span><span class="text-xs text-slate-500">{{ r.method }}</span>
                                    <a :href="route('sm.admission-payments.receipt', r.id)" target="_blank" rel="noopener" class="text-xs font-medium text-blue-700 hover:underline dark:text-blue-400">{{ t('pay_print') }}</a>
                                    <button v-if="!r.void" type="button" class="text-xs font-medium text-red-700 hover:underline" @click="voiding = r.id">{{ t('pay_void') }}</button></span>
                            </li>
                        </ul>
                        <div v-if="voiding" class="border-t border-slate-200 p-3 dark:border-slate-800">
                            <label :class="label" for="vr">{{ t('pay_void_reason') }}</label>
                            <div class="flex gap-2"><input id="vr" v-model="voidReason" type="text" maxlength="255" :class="input" /><button type="button" :disabled="!voidReason.trim()" class="h-9 rounded-md bg-red-700 px-3 text-sm font-semibold text-white disabled:opacity-50" @click="doVoid">{{ t('pay_void') }}</button><button type="button" class="h-9 rounded-md border border-slate-300 px-3 text-sm dark:border-slate-700" @click="voiding = null">{{ t('cancel') }}</button></div>
                            <p v-if="errors.reason" class="mt-1 text-xs text-red-600">{{ errors.reason }}</p>
                        </div>
                    </section>

                    <section class="rounded-md border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                        <h2 class="text-sm font-semibold">{{ t('pay_fees') }} — {{ f.session }}</h2>
                        <p class="mt-0.5 text-xs text-slate-500">{{ t('pay_fees_hint') }}</p>
                        <form class="mt-3 grid grid-cols-2 gap-2" @submit.prevent="saveFees">
                            <template v-for="c in classNames" :key="c"><label class="flex items-center text-sm" :for="'fee-' + c">{{ c }}</label><input :id="'fee-' + c" v-model="feeForm.fees[c]" type="number" min="0" step="0.01" :class="input" /></template>
                            <div class="col-span-2 mt-1"><button type="submit" :disabled="feeForm.processing" class="h-9 rounded-md bg-blue-700 px-4 text-sm font-semibold text-white hover:bg-blue-800 disabled:opacity-50">{{ t('pay_save_fees') }}</button></div>
                        </form>
                    </section>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
