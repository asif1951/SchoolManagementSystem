<script setup>
/** The frame every attendance screen shares: the pages of the module, heading, language and calendar, messages. */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useT } from '@/composables/useT'
import { useCalendarMode } from '@/lib/attendance'

defineProps({ title: { type: String, required: true }, subtitle: { type: String, default: '' } })

const t = useT('attendance')
const page = usePage()
const mode = useCalendarMode()
const locale = computed(() => page.props.locale)
const can = (...keys) => { const list = page.props.auth?.abilities ?? []; return list.includes('*') || keys.some((k) => list.includes(k) || list.includes(`${k}:own`)) }
const path = computed(() => page.url.split('?')[0].replace(/\/$/, ''))
const tabs = computed(() => [
    ['take', 'attendance.index', '/attendance', can('attendance.take', 'attendance.view')],
    ['records', 'attendance.records', '/attendance/records', can('attendance.view')],
    ['register', 'attendance.register', '/attendance/register', can('attendance.view')],
    ['leave', 'attendance.leave', '/attendance/leave', can('attendance.leave', 'attendance.leave_approve')],
    ['reports', 'attendance.reports', '/attendance/reports', can('attendance.reports')],
    ['settings', 'attendance.settings', '/attendance/settings', can('attendance.settings')],
].filter((x) => x[3]))

const toast = ref(null)
let timer = null
const notify = (text, type = 'success') => { toast.value = { text, type }; clearTimeout(timer); timer = setTimeout(() => { toast.value = null }, type === 'error' ? 9000 : 3500) }
watch(() => page.props.flash, (f) => { if (f?.error) notify(f.error, 'error'); else if (f?.success) notify(f.success) }, { immediate: true, deep: true })
const off = router.on('error', (e) => notify(Object.values(e.detail.errors)[0], 'error'))
onBeforeUnmount(() => { off(); clearTimeout(timer) })
const setLocale = (l) => router.post(route('locale'), { locale: l }, { preserveScroll: true })
defineExpose({ notify })
</script>

<template>
    <Head :title="title" />
    <AuthenticatedLayout>
        <div class="min-h-screen bg-slate-50 text-slate-800 dark:bg-slate-950 dark:text-slate-200">
            <div class="mx-auto max-w-7xl px-3 py-4 sm:px-6 lg:py-6">
                <nav class="mb-3 flex gap-1 overflow-x-auto rounded-lg border border-slate-200 bg-white p-1 dark:border-slate-800 dark:bg-slate-900" :aria-label="t('module')">
                    <Link v-for="x in tabs" :key="x[0]" :href="route(x[1])" class="shrink-0 rounded-md px-3 py-2 text-sm font-semibold transition-colors"
                          :class="path === x[2] ? 'bg-brand-700 text-white' : 'text-slate-600 hover:bg-brand-50 hover:text-brand-800 dark:text-slate-300 dark:hover:bg-slate-800'" :aria-current="path === x[2] ? 'page' : undefined">{{ t(`menu.${x[0]}`) }}</Link>
                </nav>
                <header class="mb-4 flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 pb-3 dark:border-slate-800">
                    <div class="min-w-0">
                        <h1 class="text-xl font-semibold text-slate-900 dark:text-white">{{ title }}</h1>
                        <p v-if="subtitle" class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">{{ subtitle }}</p>
                    </div>
                    <div class="flex flex-wrap items-end gap-2">
                        <slot name="actions" />
                        <label class="text-xs"><span class="sr-only">{{ t('dates_in') }}</span>
                            <select v-model="mode" class="field !w-auto" :aria-label="t('dates_in')">
                                <option v-for="c in ['english', 'bangla', 'both']" :key="c" :value="c">{{ t(`cal.${c}`) }}</option>
                            </select>
                        </label>
                        <span class="flex h-9 items-center gap-1 text-xs" role="group" :aria-label="t('language')">
                            <button type="button" class="rounded px-2 py-1" :class="locale === 'en' ? 'bg-brand-700 text-white' : 'hover:bg-slate-200 dark:hover:bg-slate-800'" @click="setLocale('en')">EN</button>
                            <button type="button" class="rounded px-2 py-1" :class="locale === 'bn' ? 'bg-brand-700 text-white' : 'hover:bg-slate-200 dark:hover:bg-slate-800'" @click="setLocale('bn')">বাংলা</button>
                        </span>
                    </div>
                </header>
                <slot />
            </div>
        </div>
        <div v-if="toast" class="fixed bottom-4 left-3 right-3 z-[70] flex items-start gap-3 rounded-md border border-l-4 border-slate-200 bg-white px-4 py-3 text-sm text-slate-800 shadow-lg sm:left-auto sm:max-w-sm dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
             :class="toast.type === 'error' ? 'border-l-red-600' : 'border-l-green-600'" :role="toast.type === 'error' ? 'alert' : 'status'">
            <span class="min-w-0 flex-1">{{ toast.text }}</span>
            <button type="button" class="text-slate-400 hover:text-slate-700" :aria-label="t('close')" @click="toast = null">×</button>
        </div>
    </AuthenticatedLayout>
</template>
