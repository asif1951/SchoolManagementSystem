<script setup>
/** The frame every Teaching Plan screen shares: heading, actions, messages. */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useT } from '@/composables/useT'

defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
    wide: { type: Boolean, default: true },
})

const t = useT('teaching')
const page = usePage()

// the same five places on every screen, with the pages inside each one underneath: nobody has to hunt
const can = (...keys) => { const list = page.props.auth?.abilities ?? []; return list.includes('*') || keys.some((k) => list.includes(k)) }
const path = computed(() => page.url.split('?')[0].replace(/\/$/, ''))
const sections = computed(() => {
    const all = can('teaching.view_all', 'teaching.manage_all', 'teaching.approve', 'teaching.review')
    const today = { key: 'today', icon: '✓', show: can('teaching.own', 'teaching.manage_all'), pages: [['today', 'teaching.log', '/teaching/log']] }
    const overview = { key: 'overview', icon: '◎', show: all, pages: [['overview', 'teaching.dashboard', '/teaching']] }
    return [
        ...(all ? [overview, today] : [today]),
        { key: 'plans', icon: '▤', show: true, pages: [['yearly', 'teaching.plans', '/teaching/plans'], ['weekly', 'teaching.period', '/teaching/period'], ['lessons', 'teaching.lessons', '/teaching/lessons']] },
        { key: 'progress', icon: '▥', show: true, pages: [['progress', 'teaching.progress', '/teaching/progress'], ['reports', 'teaching.reports', '/teaching/reports']] },
        { key: 'setup', icon: '⚙', show: can('teaching.syllabus', 'teaching.settings'), pages: [['syllabus', 'teaching.syllabus', '/teaching/syllabus'], ...(can('teaching.settings') ? [['settings', 'teaching.settings', '/teaching/settings']] : [])] },
    ].filter((s) => s.show)
})
const here = (url) => (url === '/teaching' ? path.value === url : path.value === url || path.value.startsWith(url + '/'))
const current = computed(() => sections.value.find((s) => s.pages.some((p) => here(p[2]))))
const locale = computed(() => page.props.locale)
const toast = ref(null)
let timer = null

/** pages call this through a template ref, or any child through the window event "teaching:toast" */
const notify = (text, type = 'success', undo = null) => {
    toast.value = { text, type, undo }
    clearTimeout(timer)
    timer = setTimeout(() => { toast.value = null }, type === 'error' || undo ? 9000 : 3500)
}
const onToast = (e) => notify(e.detail.text, e.detail.type, e.detail.undo)
watch(() => page.props.flash, (f) => { if (f?.error) notify(f.error, 'error'); else if (f?.success) notify(f.success) }, { immediate: true, deep: true })
// validation problems of any form on these pages are said in words
const off = router.on('error', (e) => notify(Object.values(e.detail.errors)[0], 'error'))
onMounted(() => window.addEventListener('teaching:toast', onToast))
onBeforeUnmount(() => { window.removeEventListener('teaching:toast', onToast); off(); clearTimeout(timer) })

const setLocale = (l) => router.post(route('locale'), { locale: l }, { preserveScroll: true })
const runUndo = () => { const u = toast.value.undo; toast.value = null; u() }

defineExpose({ notify })
</script>

<template>
    <Head :title="title" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-slate-50 text-slate-800 dark:bg-slate-950 dark:text-slate-200">
            <div class="mx-auto px-3 py-4 sm:px-6 lg:py-6" :class="wide ? 'max-w-7xl' : 'max-w-3xl'">
                <div class="mb-3 flex items-center gap-2">
                    <nav class="flex min-w-0 flex-1 gap-1 overflow-x-auto rounded-lg border border-slate-200 bg-white p-1 dark:border-slate-800 dark:bg-slate-900" :aria-label="t('module')">
                        <Link v-for="x in sections" :key="x.key" :href="route(x.pages[0][1])" class="flex shrink-0 items-center gap-1.5 rounded-md px-3 py-2 text-sm font-semibold transition-colors"
                              :class="current?.key === x.key ? 'bg-brand-700 text-white' : 'text-slate-600 hover:bg-brand-50 hover:text-brand-800 dark:text-slate-300 dark:hover:bg-slate-800'" :aria-current="current?.key === x.key ? 'page' : undefined">
                            <span aria-hidden="true">{{ x.icon }}</span>{{ t(`menu.${x.key}`) }}
                        </Link>
                    </nav>
                    <span class="flex shrink-0 gap-1 text-xs" role="group" :aria-label="t('language')">
                        <button type="button" class="rounded px-2 py-1" :class="locale === 'en' ? 'bg-brand-700 text-white' : 'hover:bg-slate-200 dark:hover:bg-slate-800'" @click="setLocale('en')">EN</button>
                        <button type="button" class="rounded px-2 py-1" :class="locale === 'bn' ? 'bg-brand-700 text-white' : 'hover:bg-slate-200 dark:hover:bg-slate-800'" @click="setLocale('bn')">বাংলা</button>
                    </span>
                </div>
                <nav v-if="current && current.pages.length > 1" class="mb-3 flex gap-4 overflow-x-auto border-b border-slate-200 dark:border-slate-800" :aria-label="t(`menu.${current.key}`)">
                    <Link v-for="p in current.pages" :key="p[0]" :href="route(p[1])" class="-mb-px shrink-0 border-b-2 px-1 py-2 text-sm font-medium"
                          :class="here(p[2]) ? 'border-brand-700 text-brand-800 dark:text-brand-300' : 'border-transparent text-slate-500 hover:text-slate-900 dark:hover:text-white'" :aria-current="here(p[2]) ? 'page' : undefined">{{ t(`menu.${p[0]}`) }}</Link>
                </nav>
                <header class="mb-4 flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 pb-3 dark:border-slate-800">
                    <div class="min-w-0">
                        <h1 class="text-xl font-semibold text-slate-900 dark:text-white">{{ title }}</h1>
                        <p v-if="subtitle" class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">{{ subtitle }}</p>
                    </div>
                    <div class="flex flex-wrap items-end gap-2"><slot name="actions" /></div>
                </header>
                <slot />
            </div>
        </div>

        <div v-if="toast" class="fixed bottom-4 left-3 right-3 z-[70] flex items-start gap-3 rounded-md border border-l-4 border-slate-200 bg-white px-4 py-3 text-sm text-slate-800 shadow-lg sm:left-auto sm:max-w-sm dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
             :class="toast.type === 'error' ? 'border-l-red-600' : 'border-l-green-600'" :role="toast.type === 'error' ? 'alert' : 'status'">
            <span class="min-w-0 flex-1">{{ toast.text }}</span>
            <button v-if="toast.undo" type="button" class="font-semibold text-brand-700 hover:underline" @click="runUndo">{{ t('undo') }}</button>
            <button type="button" class="text-slate-400 hover:text-slate-700" :aria-label="t('close')" @click="toast = null">×</button>
        </div>
    </AuthenticatedLayout>
</template>
