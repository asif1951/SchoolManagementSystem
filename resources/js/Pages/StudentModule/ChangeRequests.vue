<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import DuplicatePanel from '@/Components/StudentModule/DuplicatePanel.vue'
import LanguageSwitch from '@/Components/StudentModule/LanguageSwitch.vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { reactive } from 'vue'
import { useT } from '@/composables/useT'

const props = defineProps({
    requests: { type: Object, required: true },
    status: { type: String, default: 'pending' },
    counts: { type: Object, default: () => ({}) },
    canDecide: { type: Boolean, default: false },
    canOverride: { type: Boolean, default: false },
    duplicate: { type: Object, default: null },
    flash: { type: Object, default: () => ({}) },
    errors: { type: Object, default: () => ({}) },
})

const t = useT()

// one remark / override reason per request row
const remark = reactive({})
const override = reactive({})
const busy = reactive({})
const rejectError = reactive({})

const tabs = ['pending', 'approved', 'rejected']

const go = (status) => router.get(route('sm.change-requests.index'), { status }, { preserveState: true, replace: true })

const approve = (r) => {
    busy[r.id] = true
    router.post(route('sm.change-requests.approve', r.id), { remark: remark[r.id] || null, override_reason: override[r.id] || null },
        { preserveScroll: true, onFinish: () => { busy[r.id] = false } })
}

const reject = (r) => {
    if (!(remark[r.id] || '').trim()) {
        rejectError[r.id] = t('remark_required')
        return
    }
    rejectError[r.id] = ''
    busy[r.id] = true
    router.post(route('sm.change-requests.reject', r.id), { remark: remark[r.id] }, { preserveScroll: true, onFinish: () => { busy[r.id] = false } })
}

const tabClass = (s) => (props.status === s
    ? 'border-blue-700 text-blue-700 dark:border-blue-400 dark:text-blue-400'
    : 'border-transparent text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-200')
</script>

<template>
    <Head :title="t('change_requests')" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-slate-50 text-slate-800 dark:bg-slate-950 dark:text-slate-200">
            <div class="mx-auto max-w-6xl px-4 py-5 sm:px-6 lg:py-7">

                <nav class="mb-2 text-xs text-slate-500 dark:text-slate-400" aria-label="Breadcrumb">{{ t('module') }} / <span class="font-medium text-slate-700 dark:text-slate-200">{{ t('change_requests') }}</span></nav>

                <header class="mb-4 flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 pb-4 dark:border-slate-800">
                    <div>
                        <h1 class="text-xl font-semibold text-slate-900 dark:text-white">{{ t('change_requests') }}</h1>
                        <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">{{ t('protected_note') }}</p>
                    </div>
                    <LanguageSwitch />
                </header>

                <div v-if="flash.success" class="mb-4 rounded-md border border-l-4 border-slate-200 border-l-green-600 bg-white px-4 py-3 text-sm dark:border-slate-700 dark:border-l-green-500 dark:bg-slate-900" role="status">{{ flash.success }}</div>

                <DuplicatePanel
                    :duplicate="duplicate"
                    :message="errors.duplicate || ''"
                    :can-override="canOverride"
                    :reason="override[duplicate?.request_id] || ''"
                    @update:reason="(v) => override[duplicate?.request_id] = v"
                    @override="() => { const r = requests.data.find(x => x.id === duplicate?.request_id); if (r) approve(r) }"
                />

                <div class="mb-3 flex gap-1 border-b border-slate-200 dark:border-slate-800" role="tablist">
                    <button v-for="s in tabs" :key="s" type="button" role="tab" :aria-selected="status === s"
                            class="-mb-px border-b-2 px-4 py-2 text-sm font-medium" :class="tabClass(s)" @click="go(s)">
                        {{ t(s) }} <span class="ml-1 rounded bg-slate-200 px-1.5 text-xs text-slate-700 dark:bg-slate-700 dark:text-slate-200">{{ counts[s] || 0 }}</span>
                    </button>
                </div>

                <p v-if="!requests.data.length" class="rounded-md border border-slate-200 bg-white px-4 py-12 text-center text-sm text-slate-500 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400">{{ t('no_requests') }}</p>

                <ul v-else class="space-y-3">
                    <li v-for="r in requests.data" :key="r.id" class="rounded-md border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <Link v-if="r.student" :href="route('sm.students.show', r.student.id)" class="font-semibold text-slate-900 hover:text-blue-700 hover:underline dark:text-white">{{ r.student.name }}</Link>
                                <span v-if="r.student" class="ml-2 font-mono text-xs text-slate-500">{{ r.student.code }}</span>
                                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ t('requested_by') }}: {{ r.by || '—' }} · {{ r.at }}</p>
                            </div>
                            <span class="rounded border border-slate-300 px-2 py-0.5 text-xs font-semibold uppercase tracking-wide text-slate-600 dark:border-slate-600 dark:text-slate-300">{{ t('fields.' + r.field) }}</span>
                        </div>

                        <!-- old against new, side by side -->
                        <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <div class="rounded border border-slate-200 bg-slate-50 p-2.5 dark:border-slate-700 dark:bg-slate-800/50">
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ t('old_value') }}</p>
                                <p class="mt-0.5 break-words text-sm">{{ r.old || '—' }}</p>
                            </div>
                            <div class="rounded border border-blue-200 bg-blue-50 p-2.5 dark:border-blue-900 dark:bg-blue-950/30">
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-blue-700 dark:text-blue-300">{{ t('new_value') }}</p>
                                <p class="mt-0.5 break-words text-sm font-medium">{{ r.new || '—' }}</p>
                            </div>
                        </div>

                        <p class="mt-2 text-sm"><span class="text-xs font-medium text-slate-500">{{ t('reason') }}:</span> {{ r.reason }}</p>

                        <div v-if="r.status !== 'pending'" class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                            {{ t(r.status) }} · {{ r.decided_by }} · {{ r.decided_at }}<template v-if="r.remark"> — {{ r.remark }}</template>
                        </div>

                        <!-- deciding -->
                        <div v-else-if="canDecide" class="mt-3 border-t border-slate-200 pt-3 dark:border-slate-800">
                            <label class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400" :for="'remark-' + r.id">{{ t('remark') }}</label>
                            <input :id="'remark-' + r.id" v-model="remark[r.id]" type="text" maxlength="500"
                                   class="h-9 w-full rounded-md border border-slate-300 bg-white px-3 text-sm focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-600/20 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100" />
                            <p v-if="rejectError[r.id] || errors.remark" class="mt-1 text-xs text-red-600">{{ rejectError[r.id] || errors.remark }}</p>
                            <div class="mt-2 flex gap-2">
                                <button type="button" :disabled="busy[r.id]" class="h-9 rounded-md bg-green-700 px-4 text-sm font-semibold text-white hover:bg-green-800 disabled:opacity-50" @click="approve(r)">{{ t('approve') }}</button>
                                <button type="button" :disabled="busy[r.id]" class="h-9 rounded-md border border-red-300 bg-white px-4 text-sm font-semibold text-red-700 hover:bg-red-50 disabled:opacity-50 dark:border-red-800 dark:bg-slate-900 dark:text-red-400" @click="reject(r)">{{ t('reject') }}</button>
                            </div>
                        </div>
                    </li>
                </ul>

                <div v-if="requests.links && requests.links.length > 3" class="mt-4 flex flex-wrap justify-center gap-1">
                    <template v-for="(link, i) in requests.links" :key="i">
                        <span v-if="link.url === null" class="rounded-md border border-slate-200 px-3 py-1.5 text-sm text-slate-300 dark:border-slate-800" v-html="link.label"></span>
                        <Link v-else :href="link.url" preserve-scroll preserve-state :class="['rounded-md border px-3 py-1.5 text-sm font-medium', link.active ? 'border-blue-700 bg-blue-700 text-white' : 'border-slate-300 bg-white text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200']" v-html="link.label" />
                    </template>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
