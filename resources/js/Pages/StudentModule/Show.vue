<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import LanguageSwitch from '@/Components/StudentModule/LanguageSwitch.vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { reactive, ref } from 'vue'
import { useT } from '@/composables/useT'
import ProfileCard from '@/Components/Attendance/ProfileCard.vue'

const props = defineProps({
    profile: { type: Object, required: true },
    enrollments: { type: Array, default: () => [] },
    histories: { type: Array, default: () => [] },
    pending: { type: Array, default: () => [] },
    latest: { type: Object, default: () => ({ status: 'active', label: '' }) },
    can: { type: Object, default: () => ({}) },
    flash: { type: Object, default: () => ({}) },
})

const t = useT()
const confirming = ref(false)
const panel = ref(null) // 'transfer' | 'reinstate' | 'certificate'
const tform = reactive({ reason: '', destination: '' })
const rform = reactive({ reason: '' })
const cform = reactive({ type: 'testimonial', remarks: '' })
const startTransfer = () => router.post(route('sm.transfers.store'), { profile_id: props.profile.id, ...tform }, { preserveScroll: true })
const reinstate = () => router.post(route('sm.students.reinstate', props.profile.id), { ...rform }, { preserveScroll: true })
const issueCert = () => router.post(route('sm.certificates.store'), { profile_id: props.profile.id, ...cform }, { preserveScroll: true, onSuccess: () => { panel.value = null } })
const field = 'h-9 w-full rounded-md border border-slate-300 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100'

const remove = () => {
    router.delete(route('sm.students.destroy', props.profile.id), { onFinish: () => { confirming.value = false } })
}

const fieldName = (key) => t(`fields.${key}`)
const sourceName = (key) => t(`sources.${key}`)

const details = [
    ['name_bn', 'name_bn'], ['dob', 'dob'], ['gender', 'gender'], ['religion', 'religion'], ['blood_group', 'blood_group'],
    ['father_name', 'father_name'], ['mother_name', 'mother_name'], ['guardian_name', 'guardian_name'],
    ['guardian_mobile', 'mobile'], ['guardian_relation', 'guardian_relation'], ['present_address', 'present_address'],
    ['permanent_address', 'permanent_address'], ['previous_school', 'previous_school'], ['admission_date', 'admission_date'],
]

const badge = (status) => (status === 'active'
    ? 'border-green-300 bg-green-50 text-green-800 dark:border-green-800 dark:bg-green-950/40 dark:text-green-300'
    : 'border-slate-300 bg-slate-100 text-slate-700 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-300')
</script>

<template>
    <Head :title="profile.name_en" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-slate-50 text-slate-800 dark:bg-slate-950 dark:text-slate-200">
            <div class="mx-auto max-w-5xl px-4 py-5 sm:px-6 lg:py-7">

                <nav class="mb-2 text-xs text-slate-500 dark:text-slate-400" aria-label="Breadcrumb">
                    {{ t('module') }} / <Link :href="route('sm.students.index')" class="hover:underline">{{ t('entry') }}</Link> /
                    <span class="font-medium text-slate-700 dark:text-slate-200">{{ t('profile') }}</span>
                </nav>

                <header class="mb-5 flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 pb-4 dark:border-slate-800">
                    <div class="flex items-center gap-4">
                        <div class="flex h-16 w-16 flex-shrink-0 items-center justify-center overflow-hidden rounded-full bg-slate-200 text-xl font-semibold text-slate-600 dark:bg-slate-700 dark:text-slate-200">
                            <img v-if="profile.photo" :src="'/storage/' + profile.photo" :alt="profile.name_en" class="h-full w-full object-cover" loading="lazy" />
                            <span v-else>{{ profile.name_en?.charAt(0)?.toUpperCase() }}</span>
                        </div>
                        <div>
                            <h1 class="text-xl font-semibold text-slate-900 dark:text-white">{{ profile.name_en }}</h1>
                            <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">{{ t('code') }}: <span class="font-mono">{{ profile.code }}</span></p>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <LanguageSwitch />
                        <Link v-if="can.edit_this" :href="route('sm.students.edit', profile.id)" class="inline-flex h-9 items-center rounded-md border border-slate-300 bg-white px-3 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">{{ t('edit') }}</Link>
                        <button v-if="can.request_transfer && latest.status === 'active'" type="button" class="inline-flex h-9 items-center rounded-md border border-slate-300 bg-white px-3 text-sm font-medium dark:border-slate-700 dark:bg-slate-900" @click="panel = panel === 'transfer' ? null : 'transfer'">{{ t('tr_start') }}</button>
                        <button v-if="can.reinstate && latest.status === 'transferred'" type="button" class="inline-flex h-9 items-center rounded-md border border-slate-300 bg-white px-3 text-sm font-medium dark:border-slate-700 dark:bg-slate-900" @click="panel = panel === 'reinstate' ? null : 'reinstate'">{{ t('tr_reinstate') }}</button>
                        <button v-if="can.issue_certificate" type="button" class="inline-flex h-9 items-center rounded-md border border-slate-300 bg-white px-3 text-sm font-medium dark:border-slate-700 dark:bg-slate-900" @click="panel = panel === 'certificate' ? null : 'certificate'">{{ t('cert_issue') }}</button>
                        <button v-if="can.delete_this" type="button" class="inline-flex h-9 items-center rounded-md border border-red-300 bg-white px-3 text-sm font-medium text-red-700 hover:bg-red-50 dark:border-red-800 dark:bg-slate-900 dark:text-red-400" @click="confirming = true">{{ t('delete') }}</button>
                    </div>
                </header>

                <div v-if="flash.success" class="mb-4 rounded-md border border-l-4 border-slate-200 border-l-green-600 bg-white px-4 py-3 text-sm dark:border-slate-700 dark:border-l-green-500 dark:bg-slate-900" role="status">{{ flash.success }}</div>
                <div v-if="flash.error" class="mb-4 rounded-md border border-l-4 border-slate-200 border-l-red-600 bg-white px-4 py-3 text-sm dark:border-slate-700 dark:border-l-red-500 dark:bg-slate-900" role="alert">{{ flash.error }}</div>

                <p v-if="latest.status === 'transferred'" class="mb-4 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200">{{ t('tr_transferred_note') }}</p>

                <section v-if="panel === 'transfer'" class="mb-4 grid grid-cols-1 gap-3 rounded-md border border-slate-200 bg-white p-4 sm:grid-cols-2 dark:border-slate-800 dark:bg-slate-900">
                    <div><label class="mb-1 block text-xs font-medium" for="t-dest">{{ t('tr_destination') }}</label><input id="t-dest" v-model="tform.destination" type="text" maxlength="255" :class="field" /></div>
                    <div><label class="mb-1 block text-xs font-medium" for="t-reason">{{ t('tr_reason') }}</label><input id="t-reason" v-model="tform.reason" type="text" maxlength="500" :class="field" /></div>
                    <div class="sm:col-span-2"><button type="button" class="h-9 rounded-md bg-blue-700 px-4 text-sm font-semibold text-white hover:bg-blue-800" @click="startTransfer">{{ t('tr_start') }}</button></div>
                </section>
                <section v-if="panel === 'reinstate'" class="mb-4 rounded-md border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <label class="mb-1 block text-xs font-medium" for="r-reason">{{ t('tr_reinstate_reason') }}</label>
                    <div class="flex gap-2"><input id="r-reason" v-model="rform.reason" type="text" maxlength="500" :class="field" /><button type="button" :disabled="!rform.reason.trim()" class="h-9 rounded-md bg-blue-700 px-4 text-sm font-semibold text-white disabled:opacity-50" @click="reinstate">{{ t('tr_reinstate') }}</button></div>
                </section>
                <section v-if="panel === 'certificate'" class="mb-4 grid grid-cols-1 gap-3 rounded-md border border-slate-200 bg-white p-4 sm:grid-cols-3 dark:border-slate-800 dark:bg-slate-900">
                    <div><label class="mb-1 block text-xs font-medium" for="c-type">{{ t('cert_type') }}</label><select id="c-type" v-model="cform.type" :class="field"><option value="testimonial">Testimonial</option><option value="character">Character</option><option value="other">Other</option></select></div>
                    <div class="sm:col-span-2"><label class="mb-1 block text-xs font-medium" for="c-rem">{{ t('cert_remarks') }}</label><input id="c-rem" v-model="cform.remarks" type="text" maxlength="500" :class="field" /></div>
                    <div class="sm:col-span-3"><button type="button" class="h-9 rounded-md bg-blue-700 px-4 text-sm font-semibold text-white hover:bg-blue-800" @click="issueCert">{{ t('cert_issue') }}</button></div>
                </section>

                <!-- delete confirmation: stated inline, never a browser pop-up -->
                <div v-if="confirming" class="mb-4 rounded-md border border-red-300 bg-red-50 p-4 text-sm dark:border-red-800 dark:bg-red-950/30" role="alertdialog">
                    <p class="font-semibold text-red-900 dark:text-red-200">{{ t('delete') }}: {{ profile.name_en }}?</p>
                    <div class="mt-3 flex gap-2">
                        <button type="button" class="h-9 rounded-md bg-red-700 px-4 text-sm font-semibold text-white hover:bg-red-800" @click="remove">{{ t('delete') }}</button>
                        <button type="button" class="h-9 rounded-md border border-slate-300 bg-white px-4 text-sm font-medium text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200" @click="confirming = false">{{ t('cancel') }}</button>
                    </div>
                </div>

                <!-- changes waiting for approval -->
                <section v-if="pending.length" class="mb-5 rounded-md border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950/30">
                    <h2 class="text-sm font-semibold text-blue-900 dark:text-blue-200">{{ t('pending_changes') }}</h2>
                    <ul class="mt-2 space-y-1 text-sm text-blue-900 dark:text-blue-200">
                        <li v-for="r in pending" :key="r.id">{{ fieldName(r.field) }}: <span class="line-through opacity-70">{{ r.old || '—' }}</span> → <strong>{{ r.new || '—' }}</strong> <span class="text-xs opacity-80">({{ r.by }}, {{ r.at }})</span></li>
                    </ul>
                </section>

                <!-- personal details -->
                <section class="mb-5 rounded-md border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="border-b border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-800 dark:border-slate-800 dark:text-slate-100">{{ t('personal') }}</h2>
                    <dl class="grid grid-cols-1 gap-x-6 gap-y-3 p-4 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">{{ t('brn') }}</dt>
                            <dd>
                                <span v-if="profile.brn_missing" class="inline-block rounded border border-amber-300 bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300">{{ t('brn_missing') }}</span>
                                <span v-else class="font-mono">{{ profile.brn }}</span>
                            </dd>
                        </div>
                        <div v-for="[key, text] in details" :key="key">
                            <dt class="text-xs font-medium text-slate-500 dark:text-slate-400">{{ t(text) }}</dt>
                            <dd class="break-words">{{ profile[key] || '—' }}</dd>
                        </div>
                    </dl>
                </section>

                <ProfileCard v-if="enrollments.length" :enrollments="enrollments" />

                <!-- enrollment history -->
                <section class="mb-5 overflow-hidden rounded-md border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="border-b border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-800 dark:border-slate-800 dark:text-slate-100">{{ t('enrollment_history') }}</h2>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[520px] text-sm">
                            <thead class="bg-slate-100 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                <tr><th class="px-4 py-2">{{ t('session') }}</th><th class="px-4 py-2">{{ t('class_section') }}</th><th class="px-4 py-2">{{ t('roll') }}</th><th class="px-4 py-2">{{ t('status') }}</th></tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                                <tr v-for="e in enrollments" :key="e.id">
                                    <td class="px-4 py-2 tabular-nums">{{ e.session }}</td>
                                    <td class="px-4 py-2">{{ e.class }}<span v-if="e.shift || e.medium" class="text-xs text-slate-500"> · {{ [e.shift, e.medium].filter(Boolean).join(' · ') }}</span></td>
                                    <td class="px-4 py-2 tabular-nums">{{ e.roll || '—' }}</td>
                                    <td class="px-4 py-2"><span class="inline-block rounded border px-2 py-0.5 text-xs font-semibold" :class="badge(e.status)">{{ e.status_label }}</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- change history -->
                <section class="overflow-hidden rounded-md border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="border-b border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-800 dark:border-slate-800 dark:text-slate-100">{{ t('change_history') }}</h2>
                    <p v-if="!histories.length" class="px-4 py-6 text-sm text-slate-500 dark:text-slate-400">{{ t('no_history') }}</p>
                    <div v-else class="overflow-x-auto">
                        <table class="w-full min-w-[640px] text-sm">
                            <thead class="bg-slate-100 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                <tr><th class="px-4 py-2">{{ t('field') }}</th><th class="px-4 py-2">{{ t('old_value') }}</th><th class="px-4 py-2">{{ t('new_value') }}</th><th class="px-4 py-2">{{ t('changed_by') }}</th><th class="px-4 py-2">{{ t('source') }}</th><th class="px-4 py-2">{{ t('when') }}</th></tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                                <tr v-for="h in histories" :key="h.id">
                                    <td class="px-4 py-2 font-medium">{{ fieldName(h.field) }}</td>
                                    <td class="px-4 py-2 text-slate-500">{{ h.old || '—' }}</td>
                                    <td class="px-4 py-2">{{ h.new || '—' }}</td>
                                    <td class="px-4 py-2">{{ h.by || '—' }}</td>
                                    <td class="px-4 py-2 text-xs">{{ sourceName(h.source) }}</td>
                                    <td class="px-4 py-2 text-xs text-slate-500">{{ h.at }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
