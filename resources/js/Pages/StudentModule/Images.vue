<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import LanguageSwitch from '@/Components/StudentModule/LanguageSwitch.vue'
import TaskProgress from '@/Components/StudentModule/TaskProgress.vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useT } from '@/composables/useT'

const props = defineProps({
    recent: { type: Array, default: () => [] },
    maxKb: { type: Number, default: 2048 },
    maxZip: { type: Number, default: 2000 },
    task: { type: Number, default: null },
    flash: { type: Object, default: () => ({}) },
    errors: { type: Object, default: () => ({}) },
})

const t = useT()

// ---------------------------------------------------------------- one student
const query = ref('')
const results = ref([])
const chosen = ref(null)
const preview = ref(null)
const single = useForm({ photo: null })
let timer = null

watch(query, (q) => {
    clearTimeout(timer)
    if (q.trim().length < 2) {
        results.value = []
        return
    }
    timer = setTimeout(async () => {
        const res = await fetch(route('sm.images.find', { q }), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        results.value = res.ok ? await res.json() : []
    }, 300)
})
onBeforeUnmount(() => clearTimeout(timer))

const pick = (s) => {
    chosen.value = s
    results.value = []
    query.value = ''
}

const onFile = (e) => {
    const file = e.target.files?.[0] || null
    single.photo = file
    preview.value = file ? URL.createObjectURL(file) : null
}

const uploadOne = () => {
    single.post(route('sm.images.store', chosen.value.id), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            single.reset()
            preview.value = null
            chosen.value = null
        },
    })
}

// ---------------------------------------------------------------- many students
const bulk = useForm({ files: [], zip: null })
const currentTask = ref(props.task)
const finished = ref(null)

const onFiles = (e) => { bulk.files = Array.from(e.target.files || []) }
const onZip = (e) => { bulk.zip = e.target.files?.[0] || null }

const startBulk = () => {
    finished.value = null
    bulk.post(route('sm.images.bulk'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: (page) => {
            currentTask.value = page.props.task
            bulk.reset()
        },
    })
}

const done = (task) => { finished.value = task }

const matched = computed(() => finished.value?.summary?.matched ?? [])
const unmatched = computed(() => finished.value?.summary?.unmatched ?? [])

const input = 'block w-full text-sm text-slate-700 file:mr-3 file:cursor-pointer file:rounded-md file:border file:border-slate-300 file:bg-white file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-50 dark:text-slate-300'
</script>

<template>
    <Head :title="t('images_title')" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-slate-50 text-slate-800 dark:bg-slate-950 dark:text-slate-200">
            <div class="mx-auto max-w-5xl px-4 py-5 sm:px-6 lg:py-7">

                <nav class="mb-2 text-xs text-slate-500 dark:text-slate-400" aria-label="Breadcrumb">{{ t('module') }} / <span class="font-medium text-slate-700 dark:text-slate-200">{{ t('images_title') }}</span></nav>

                <header class="mb-5 flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 pb-4 dark:border-slate-800">
                    <h1 class="text-xl font-semibold text-slate-900 dark:text-white">{{ t('images_title') }}</h1>
                    <LanguageSwitch />
                </header>

                <div v-if="flash.success" class="mb-4 rounded-md border border-l-4 border-slate-200 border-l-green-600 bg-white px-4 py-3 text-sm dark:border-slate-700 dark:border-l-green-500 dark:bg-slate-900" role="status">{{ flash.success }}</div>

                <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">

                    <!-- one student -->
                    <section class="rounded-md border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                        <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ t('single_photo') }}</h2>

                        <div v-if="!chosen" class="relative mt-3">
                            <label class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400" for="find">{{ t('find_student') }}</label>
                            <input id="find" v-model="query" type="search" autocomplete="off"
                                   class="h-9 w-full rounded-md border border-slate-300 bg-white px-3 text-sm focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-600/20 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100" />
                            <ul v-if="results.length" class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-md border border-slate-200 bg-white shadow-lg dark:border-slate-700 dark:bg-slate-900">
                                <li v-for="s in results" :key="s.id">
                                    <button type="button" class="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-slate-100 dark:hover:bg-slate-800" @click="pick(s)">
                                        <span class="font-medium">{{ s.name }}</span><span class="font-mono text-xs text-slate-500">{{ s.code }}</span>
                                    </button>
                                </li>
                            </ul>
                        </div>

                        <div v-else class="mt-3">
                            <p class="text-sm"><span class="font-semibold">{{ chosen.name }}</span> <span class="font-mono text-xs text-slate-500">{{ chosen.code }}</span>
                                <button type="button" class="ml-2 text-xs font-medium text-blue-700 hover:underline dark:text-blue-400" @click="chosen = null; preview = null">×</button></p>

                            <div class="mt-3 flex items-start gap-4">
                                <div class="text-center">
                                    <div class="flex h-24 w-24 items-center justify-center overflow-hidden rounded-md border border-slate-200 bg-slate-100 dark:border-slate-700 dark:bg-slate-800">
                                        <img v-if="preview" :src="preview" alt="" class="h-full w-full object-cover" />
                                        <img v-else-if="chosen.photo" :src="'/storage/' + chosen.photo" alt="" class="h-full w-full object-cover" loading="lazy" />
                                        <span v-else class="px-2 text-xs text-slate-400">{{ t('no_photo') }}</span>
                                    </div>
                                    <p class="mt-1 text-[11px] text-slate-500">{{ preview ? t('upload') : t('current_photo') }}</p>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <input type="file" accept="image/jpeg,image/png,image/webp" :class="input" aria-label="Photo" @change="onFile" />
                                    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ t('photo_rules', { kb: maxKb }) }}</p>
                                    <p v-if="single.errors.photo || errors.photo" class="mt-1 text-xs text-red-600" role="alert">{{ single.errors.photo || errors.photo }}</p>
                                    <button type="button" :disabled="!single.photo || single.processing" class="mt-3 h-9 rounded-md bg-blue-700 px-4 text-sm font-semibold text-white hover:bg-blue-800 disabled:opacity-50" @click="uploadOne">{{ t('upload') }}</button>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- many students -->
                    <section class="rounded-md border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                        <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ t('bulk_photos') }}</h2>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ t('bulk_hint', { max: maxZip }) }}</p>

                        <div class="mt-3 space-y-3">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">{{ t('choose_files') }}</label>
                                <input type="file" multiple accept="image/jpeg,image/png,image/webp" :class="input" @change="onFiles" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">{{ t('choose_zip') }}</label>
                                <input type="file" accept=".zip,application/zip" :class="input" @change="onZip" />
                            </div>
                            <p v-if="bulk.errors.files || bulk.errors.zip || errors.files" class="text-xs text-red-600" role="alert">{{ bulk.errors.files || bulk.errors.zip || errors.files }}</p>
                            <button type="button" :disabled="bulk.processing || (!bulk.files.length && !bulk.zip)" class="h-9 rounded-md bg-blue-700 px-4 text-sm font-semibold text-white hover:bg-blue-800 disabled:opacity-50" @click="startBulk">{{ t('start_upload') }}</button>
                        </div>

                        <div v-if="currentTask" class="mt-5 border-t border-slate-200 pt-4 dark:border-slate-800">
                            <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('progress') }}</h3>
                            <TaskProgress :key="currentTask" :task-id="currentTask" @finished="done" />
                        </div>
                    </section>
                </div>

                <!-- result of the batch just finished -->
                <section v-if="finished && finished.status === 'done'" class="mt-5 rounded-md border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-2.5 dark:border-slate-800">
                        <h2 class="text-sm font-semibold">{{ t('matched') }}: {{ matched.length }} · {{ t('unmatched') }}: {{ unmatched.length }}</h2>
                        <a v-if="unmatched.length" :href="route('sm.tasks.report', finished.id)" class="text-sm font-medium text-blue-700 hover:underline dark:text-blue-400">{{ t('download_report') }}</a>
                    </div>
                    <div class="grid grid-cols-1 gap-0 divide-y divide-slate-200 md:grid-cols-2 md:divide-x md:divide-y-0 dark:divide-slate-800">
                        <div class="max-h-72 overflow-auto p-4">
                            <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-green-700 dark:text-green-400">{{ t('matched') }}</h3>
                            <ul class="space-y-1 text-sm"><li v-for="m in matched" :key="m.file">{{ m.name }} <span class="font-mono text-xs text-slate-500">{{ m.code }}</span></li></ul>
                        </div>
                        <div class="max-h-72 overflow-auto p-4">
                            <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-400">{{ t('unmatched') }}</h3>
                            <ul class="space-y-1 text-sm"><li v-for="u in unmatched" :key="u.file"><span class="font-mono text-xs">{{ u.file }}</span> — <span class="text-slate-600 dark:text-slate-400">{{ u.reason }}</span></li></ul>
                        </div>
                    </div>
                </section>

                <section v-if="recent.length" class="mt-5 rounded-md border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <h2 class="mb-2 text-sm font-semibold">{{ t('recent_batches') }}</h2>
                    <ul class="space-y-1 text-sm">
                        <li v-for="r in recent" :key="r.id" class="flex flex-wrap items-center justify-between gap-2">
                            <span>#{{ r.id }} — {{ t(r.status === 'done' ? 'finished' : r.status === 'failed' ? 'failed' : 'working') }}</span>
                            <span class="tabular-nums text-xs text-slate-500">{{ r.summary?.matched_count ?? 0 }} {{ t('matched') }} · {{ r.summary?.unmatched_count ?? 0 }} {{ t('unmatched') }}</span>
                        </li>
                    </ul>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
