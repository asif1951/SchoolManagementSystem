<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import LanguageSwitch from '@/Components/StudentModule/LanguageSwitch.vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { onBeforeUnmount, reactive, ref, watch } from 'vue'
import { useT } from '@/composables/useT'

const props = defineProps({
    applications: { type: Object, required: true },
    status: { type: String, default: 'pending' },
    counts: { type: Object, default: () => ({}) },
    sessions: { type: Array, default: () => [] },
    classes: { type: Array, default: () => [] },
    filters: { type: Object, required: true },
    canOverride: { type: Boolean, default: false },
    duplicate: { type: Object, default: null },
    flash: { type: Object, default: () => ({}) },
    errors: { type: Object, default: () => ({}) },
})

const t = useT()
const tabs = [['pending', 'pending'], ['approved', 'approved'], ['rejected', 'rejected']]
const f = reactive({ ...props.filters })

let timer = null
const load = (extra = {}) => {
    const q = { ...f, status: props.status, ...extra }
    Object.keys(q).forEach((k) => { if (!q[k]) delete q[k] })
    router.get(route('sm.online-admissions.index'), q, { preserveState: true, preserveScroll: true, replace: true })
}
watch(() => f.q, () => { clearTimeout(timer); timer = setTimeout(() => load(), 350) })
onBeforeUnmount(() => clearTimeout(timer))

// the application being decided
const open = ref(null)
const place = reactive({ class_id: '', roll_number: '', override_reason: '', remark: '' })
const classesFor = (a) => props.classes.filter((c) => c.session === String(a.session))
const choose = (a) => { open.value = open.value === a.id ? null : a.id; Object.assign(place, { class_id: '', roll_number: '', override_reason: '', remark: '' }) }
const approve = (a) => router.post(route('sm.online-admissions.approve', a.id), { class_id: place.class_id, roll_number: place.roll_number || null, override_reason: place.override_reason || null }, { preserveScroll: true })
const reject = (a) => router.post(route('sm.online-admissions.reject', a.id), { remark: place.remark }, { preserveScroll: true, onSuccess: () => { open.value = null } })

const input = 'h-9 w-full rounded-md border border-slate-300 bg-white px-3 text-sm focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-600/20 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100'
const label = 'mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400'
</script>

<template>
    <Head :title="t('oa_title')" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-slate-50 text-slate-800 dark:bg-slate-950 dark:text-slate-200">
            <div class="mx-auto max-w-6xl px-4 py-5 sm:px-6 lg:py-7">

                <nav class="mb-2 text-xs text-slate-500 dark:text-slate-400" aria-label="Breadcrumb">{{ t('module') }} / <span class="font-medium text-slate-700 dark:text-slate-200">{{ t('oa_title') }}</span></nav>
                <header class="mb-4 flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 pb-4 dark:border-slate-800">
                    <h1 class="text-xl font-semibold text-slate-900 dark:text-white">{{ t('oa_title') }}</h1>
                    <LanguageSwitch />
                </header>

                <div v-if="flash.success" class="mb-4 rounded-md border border-l-4 border-slate-200 border-l-green-600 bg-white px-4 py-3 text-sm dark:border-slate-700 dark:border-l-green-500 dark:bg-slate-900" role="status">{{ flash.success }}</div>
                <div v-if="errors.duplicate || flash.error || errors.class_id || errors.override_reason" class="mb-4 rounded-md border border-l-4 border-slate-200 border-l-red-600 bg-white px-4 py-3 text-sm dark:border-slate-700 dark:border-l-red-500 dark:bg-slate-900" role="alert">
                    {{ errors.duplicate || flash.error || errors.class_id || errors.override_reason }}
                    <p v-if="duplicate && canOverride" class="mt-1 text-xs text-slate-500">{{ t('override_hint') }}</p>
                </div>

                <section class="mb-3 grid grid-cols-1 gap-3 rounded-md border border-slate-200 bg-white p-4 sm:grid-cols-3 dark:border-slate-800 dark:bg-slate-900">
                    <div><label :class="label" for="q">{{ t('oa_search') }}</label><input id="q" v-model="f.q" type="search" :placeholder="t('oa_search')" :class="input" /></div>
                    <div><label :class="label" for="s">{{ t('session') }}</label><select id="s" v-model="f.session" :class="input" @change="load()"><option value="">—</option><option v-for="y in sessions" :key="y" :value="y">{{ y }}</option></select></div>
                    <div><label :class="label" for="c">{{ t('oa_class') }}</label><input id="c" v-model="f.class_name" type="text" :class="input" @change="load()" /></div>
                </section>

                <div class="mb-3 flex gap-1 border-b border-slate-200 dark:border-slate-800" role="tablist">
                    <button v-for="[s, key] in tabs" :key="s" type="button" role="tab" :aria-selected="status === s" class="-mb-px border-b-2 px-4 py-2 text-sm font-medium"
                            :class="status === s ? 'border-blue-700 text-blue-700 dark:border-blue-400 dark:text-blue-400' : 'border-transparent text-slate-600 hover:text-slate-900 dark:text-slate-400'" @click="load({ status: s })">
                        {{ t(key) }} <span class="ml-1 rounded bg-slate-200 px-1.5 text-xs dark:bg-slate-700">{{ counts[s] || 0 }}</span>
                    </button>
                </div>

                <p v-if="!applications.data.length" class="rounded-md border border-slate-200 bg-white px-4 py-12 text-center text-sm text-slate-500 dark:border-slate-800 dark:bg-slate-900">{{ t('oa_none') }}</p>

                <ul v-else class="space-y-3">
                    <li v-for="a in applications.data" :key="a.id" class="rounded-md border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-semibold text-slate-900 dark:text-white">{{ a.name }}<span v-if="a.name_bn" class="ml-2 font-normal text-slate-500">{{ a.name_bn }}</span></p>
                                <p class="text-xs text-slate-500"><span class="font-mono">{{ a.no }}</span> · {{ a.session }} · {{ t('oa_class') }}: {{ a.class_name }} · {{ t('oa_submitted') }} {{ a.submitted }}</p>
                            </div>
                            <button v-if="status === 'pending'" type="button" class="h-9 rounded-md border border-slate-300 px-3 text-sm font-medium hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800" :aria-expanded="open === a.id" @click="choose(a)">{{ t('oa_approve') }} / {{ t('oa_reject') }}</button>
                            <Link v-else-if="a.student_profile_id" :href="route('sm.students.show', a.student_profile_id)" class="text-sm font-medium text-blue-700 hover:underline dark:text-blue-400">{{ t('oa_open_student') }}</Link>
                        </div>

                        <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-sm sm:grid-cols-4">
                            <div><dt class="text-xs text-slate-500">{{ t('dob') }}</dt><dd>{{ a.dob }}</dd></div>
                            <div><dt class="text-xs text-slate-500">{{ t('gender') }}</dt><dd>{{ a.gender }}</dd></div>
                            <div><dt class="text-xs text-slate-500">{{ t('father_name') }}</dt><dd>{{ a.father }}</dd></div>
                            <div><dt class="text-xs text-slate-500">{{ t('mother_name') }}</dt><dd>{{ a.mother || '—' }}</dd></div>
                            <div><dt class="text-xs text-slate-500">{{ t('mobile') }}</dt><dd>{{ a.mobile }}</dd></div>
                            <div><dt class="text-xs text-slate-500">BRN</dt><dd>{{ a.brn_given ? '✓' : '—' }}</dd></div>
                            <div class="col-span-2"><dt class="text-xs text-slate-500">{{ t('previous_school') }}</dt><dd>{{ a.previous_school || '—' }}</dd></div>
                        </dl>

                        <p v-if="a.status !== 'pending'" class="mt-2 text-xs text-slate-500">{{ a.decided_by }} · {{ a.decided_at }}<template v-if="a.remark"> — {{ a.remark }}</template></p>

                        <div v-if="open === a.id" class="mt-3 grid grid-cols-1 gap-3 border-t border-slate-200 pt-3 sm:grid-cols-4 dark:border-slate-800">
                            <div class="sm:col-span-2"><label :class="label" :for="'cl' + a.id">{{ t('oa_place') }}</label>
                                <select :id="'cl' + a.id" v-model="place.class_id" :class="input"><option value="">—</option><option v-for="c in classesFor(a)" :key="c.id" :value="c.id">{{ c.label }}</option></select></div>
                            <div><label :class="label" :for="'rl' + a.id">{{ t('oa_roll') }}</label><input :id="'rl' + a.id" v-model="place.roll_number" type="text" maxlength="50" :class="input" /></div>
                            <div v-if="canOverride && duplicate && duplicate.application_id === a.id"><label :class="label" :for="'ov' + a.id">{{ t('override_reason') }}</label><input :id="'ov' + a.id" v-model="place.override_reason" type="text" maxlength="500" :class="input" /></div>
                            <div class="sm:col-span-4 flex flex-wrap items-end gap-2">
                                <button type="button" :disabled="!place.class_id" class="h-9 rounded-md bg-blue-700 px-4 text-sm font-semibold text-white hover:bg-blue-800 disabled:opacity-50" @click="approve(a)">{{ t('oa_approve') }}</button>
                                <input v-model="place.remark" type="text" maxlength="500" :placeholder="t('tr_remark')" :aria-label="t('tr_remark')" :class="[input, 'max-w-xs']" />
                                <button type="button" :disabled="!place.remark.trim()" class="h-9 rounded-md border border-red-300 px-4 text-sm font-semibold text-red-700 hover:bg-red-50 disabled:opacity-50 dark:border-red-800 dark:text-red-400" @click="reject(a)">{{ t('oa_reject') }}</button>
                            </div>
                        </div>
                    </li>
                </ul>

                <div v-if="applications.links && applications.links.length > 3" class="mt-4 flex flex-wrap justify-center gap-1">
                    <template v-for="(link, i) in applications.links" :key="i">
                        <span v-if="link.url === null" class="rounded-md border border-slate-200 px-3 py-1.5 text-sm text-slate-300 dark:border-slate-800" v-html="link.label"></span>
                        <Link v-else :href="link.url" preserve-scroll preserve-state :class="['rounded-md border px-3 py-1.5 text-sm font-medium', link.active ? 'border-blue-700 bg-blue-700 text-white' : 'border-slate-300 bg-white text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200']" v-html="link.label" />
                    </template>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
