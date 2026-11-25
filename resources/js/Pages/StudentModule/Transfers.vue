<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import LanguageSwitch from '@/Components/StudentModule/LanguageSwitch.vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { reactive } from 'vue'
import { useT } from '@/composables/useT'

const props = defineProps({
    transfers: { type: Object, required: true },
    status: { type: String, default: 'open' },
    counts: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
    flash: { type: Object, default: () => ({}) },
    errors: { type: Object, default: () => ({}) },
})

const t = useT()
const tabs = [['open', 'tr_open'], ['issued', 'tr_issued'], ['rejected', 'tr_rejected'], ['cancelled', 'tr_cancelled']]
const money = (v) => Number(v || 0).toLocaleString('en-BD', { minimumFractionDigits: 2 })

const text = reactive({})          // a remark / reason box per transfer
const busy = reactive({})
const post = (name, id, data = {}) => {
    busy[id] = true
    router.post(route(name, id), data, { preserveScroll: true, onFinish: () => { busy[id] = false } })
}

const steps = (tr) => [
    { label: t('tr_requested'), done: true, info: [tr.trail.requested.by, tr.trail.requested.at] },
    { label: t('tr_cleared'), done: !!tr.trail.cleared, info: [tr.trail.cleared?.at] },
    { label: t('tr_approved'), done: !!tr.trail.approved, info: [tr.trail.approved?.by, tr.trail.approved?.at] },
    { label: t('tr_cert_issued'), done: !!tr.trail.issued, info: [tr.trail.issued?.serial, tr.trail.issued?.at] },
]

const input = 'h-9 w-full rounded-md border border-slate-300 bg-white px-3 text-sm focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-600/20 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100'
</script>

<template>
    <Head :title="t('tr_title')" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-slate-50 text-slate-800 dark:bg-slate-950 dark:text-slate-200">
            <div class="mx-auto max-w-5xl px-4 py-5 sm:px-6 lg:py-7">

                <nav class="mb-2 text-xs text-slate-500 dark:text-slate-400" aria-label="Breadcrumb">{{ t('module') }} / <span class="font-medium text-slate-700 dark:text-slate-200">{{ t('tr_title') }}</span></nav>
                <header class="mb-4 flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 pb-4 dark:border-slate-800">
                    <h1 class="text-xl font-semibold text-slate-900 dark:text-white">{{ t('tr_title') }}</h1>
                    <LanguageSwitch />
                </header>

                <div v-if="flash.success" class="mb-4 rounded-md border border-l-4 border-slate-200 border-l-green-600 bg-white px-4 py-3 text-sm dark:border-slate-700 dark:border-l-green-500 dark:bg-slate-900" role="status">{{ flash.success }}</div>
                <div v-if="flash.error || errors.dues || errors.override_reason || errors.status || errors.remark" class="mb-4 rounded-md border border-l-4 border-slate-200 border-l-red-600 bg-white px-4 py-3 text-sm dark:border-slate-700 dark:border-l-red-500 dark:bg-slate-900" role="alert">{{ flash.error || errors.dues || errors.override_reason || errors.status || errors.remark }}</div>

                <div class="mb-3 flex gap-1 border-b border-slate-200 dark:border-slate-800" role="tablist">
                    <button v-for="[s, key] in tabs" :key="s" type="button" role="tab" :aria-selected="status === s" class="-mb-px border-b-2 px-4 py-2 text-sm font-medium"
                            :class="status === s ? 'border-blue-700 text-blue-700 dark:border-blue-400 dark:text-blue-400' : 'border-transparent text-slate-600 hover:text-slate-900 dark:text-slate-400'"
                            @click="router.get(route('sm.transfers.index'), { status: s }, { preserveState: true, replace: true })">
                        {{ t(key) }} <span class="ml-1 rounded bg-slate-200 px-1.5 text-xs dark:bg-slate-700">{{ counts[s] || 0 }}</span>
                    </button>
                </div>

                <p v-if="!transfers.data.length" class="rounded-md border border-slate-200 bg-white px-4 py-12 text-center text-sm text-slate-500 dark:border-slate-800 dark:bg-slate-900">{{ t('tr_none') }}</p>

                <ul v-else class="space-y-3">
                    <li v-for="tr in transfers.data" :key="tr.id" class="rounded-md border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <Link :href="route('sm.students.show', tr.student.id)" class="font-semibold text-slate-900 hover:text-blue-700 hover:underline dark:text-white">{{ tr.student.name }}</Link>
                                <span class="ml-2 font-mono text-xs text-slate-500">{{ tr.student.code }}</span>
                                <p class="text-xs text-slate-500">{{ tr.student.class }} · {{ tr.student.session }}<template v-if="tr.destination"> → {{ tr.destination }}</template></p>
                                <p v-if="tr.reason" class="mt-1 text-sm">{{ tr.reason }}</p>
                            </div>
                            <span class="rounded border px-2 py-0.5 text-xs font-semibold uppercase tracking-wide" :class="tr.dues_total > 0 ? 'border-red-300 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-950/40 dark:text-red-300' : 'border-green-300 bg-green-50 text-green-800 dark:border-green-800 dark:bg-green-950/40 dark:text-green-300'">
                                {{ tr.dues_total > 0 ? t('tr_dues') + ' ৳ ' + money(tr.dues_total) : t('tr_no_dues') }}
                            </span>
                        </div>

                        <!-- the status trail -->
                        <ol class="mt-3 grid grid-cols-2 gap-2 text-xs sm:grid-cols-4" :aria-label="t('tr_trail')">
                            <li v-for="s in steps(tr)" :key="s.label" class="rounded border px-2 py-1.5" :class="s.done ? 'border-green-300 bg-green-50 dark:border-green-800 dark:bg-green-950/30' : 'border-slate-200 text-slate-400 dark:border-slate-700'">
                                <p class="font-semibold">{{ s.done ? '✓ ' : '' }}{{ s.label }}</p>
                                <p v-for="(x, i) in s.info.filter(Boolean)" :key="i" class="truncate">{{ x }}</p>
                            </li>
                        </ol>

                        <ul v-if="tr.dues.length && tr.status !== 'issued'" class="mt-2 text-xs text-slate-600 dark:text-slate-400">
                            <li v-for="d in tr.dues" :key="d.label">{{ d.label }}: ৳ {{ money(d.expected) }} − ৳ {{ money(d.paid) }} = <b>৳ {{ money(d.due) }}</b></li>
                        </ul>

                        <p v-if="tr.override_reason" class="mt-2 rounded border border-amber-300 bg-amber-50 px-2 py-1 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200">{{ t('tr_overridden') }}: {{ tr.override_reason }}</p>
                        <p v-if="tr.remark" class="mt-2 text-xs text-slate-500">{{ t('tr_remark') }}: {{ tr.remark }}</p>
                        <p v-if="tr.reinstated" class="mt-2 text-xs text-blue-700 dark:text-blue-300">{{ t('tr_reinstated') }} {{ tr.reinstated.at }}: {{ tr.reinstated.reason }}</p>

                        <div v-if="tr.status === 'issued' && tr.trail.issued?.certificate_id" class="mt-3">
                            <a :href="route('sm.certificates.print', tr.trail.issued.certificate_id)" target="_blank" rel="noopener" class="inline-flex h-9 items-center rounded-md border border-slate-300 px-3 text-sm font-medium hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">{{ t('cert_print') }} {{ tr.trail.issued.serial }}</a>
                        </div>

                        <!-- an open transfer -->
                        <div v-if="['requested', 'cleared'].includes(tr.status)" class="mt-3 border-t border-slate-200 pt-3 dark:border-slate-800">
                            <p v-if="tr.dues_total > 0" class="mb-2 text-xs text-red-700 dark:text-red-400">{{ t('tr_blocked') }} <span v-if="can.override">{{ t('tr_override_note') }}</span></p>

                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-[1fr_auto]">
                                <div class="space-y-2">
                                    <input v-if="tr.dues_total > 0 && can.override" v-model="text['o' + tr.id]" type="text" maxlength="500" :placeholder="t('tr_override')" :class="input" :aria-label="t('tr_override')" />
                                    <input v-model="text['r' + tr.id]" type="text" maxlength="500" :placeholder="t('tr_remark')" :class="input" :aria-label="t('tr_remark')" />
                                </div>
                                <div class="flex flex-wrap items-start gap-2">
                                    <button type="button" :disabled="busy[tr.id]" class="h-9 rounded-md border border-slate-300 px-3 text-sm font-medium hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" @click="post('sm.transfers.recheck', tr.id)">{{ t('tr_recheck') }}</button>
                                    <button v-if="can.approve" type="button" :disabled="busy[tr.id] || (tr.dues_total > 0 && !(can.override && (text['o' + tr.id] || '').trim()))" class="h-9 rounded-md bg-blue-700 px-3 text-sm font-semibold text-white hover:bg-blue-800 disabled:opacity-50" @click="post('sm.transfers.approve', tr.id, { override_reason: text['o' + tr.id] || null })">{{ t('tr_approve') }}</button>
                                    <button v-if="can.approve" type="button" :disabled="busy[tr.id] || !(text['r' + tr.id] || '').trim()" class="h-9 rounded-md border border-red-300 px-3 text-sm font-semibold text-red-700 hover:bg-red-50 disabled:opacity-50 dark:border-red-800 dark:text-red-400" @click="post('sm.transfers.reject', tr.id, { remark: text['r' + tr.id] })">{{ t('tr_reject') }}</button>
                                    <button type="button" :disabled="busy[tr.id] || !(text['r' + tr.id] || '').trim()" class="h-9 rounded-md border border-slate-300 px-3 text-sm hover:bg-slate-100 disabled:opacity-50 dark:border-slate-700 dark:hover:bg-slate-800" @click="post('sm.transfers.cancel', tr.id, { remark: text['r' + tr.id] })">{{ t('tr_cancel') }}</button>
                                </div>
                            </div>
                        </div>
                    </li>
                </ul>

                <div v-if="transfers.links && transfers.links.length > 3" class="mt-4 flex flex-wrap justify-center gap-1">
                    <template v-for="(link, i) in transfers.links" :key="i">
                        <span v-if="link.url === null" class="rounded-md border border-slate-200 px-3 py-1.5 text-sm text-slate-300 dark:border-slate-800" v-html="link.label"></span>
                        <Link v-else :href="link.url" preserve-scroll preserve-state :class="['rounded-md border px-3 py-1.5 text-sm font-medium', link.active ? 'border-blue-700 bg-blue-700 text-white' : 'border-slate-300 bg-white text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200']" v-html="link.label" />
                    </template>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
