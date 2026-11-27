<script setup>
/** On the dashboard: which classes still owe today's attendance, and how many students are low. */
import { Link, usePage } from '@inertiajs/vue3'
import { computed, onMounted, ref } from 'vue'
import { SOFT, num } from '@/lib/attendance'

const fill = (text, values) => Object.entries(values).reduce((s, [k, v]) => s.replaceAll(`:${k}`, v), text)
const page = usePage()
const allowed = computed(() => (page.props.auth?.abilities ?? []).some((a) => a === '*' || a.startsWith('attendance.view')))
const data = ref(null)
const n = (v) => num(v, page.props.locale)
onMounted(async () => {
    if (!allowed.value) return
    try { data.value = (await window.axios.get(route('attendance.today'))).data } catch { data.value = null }       // the screen works without this box
})
const done = computed(() => data.value.classes.filter((c) => c.state === 'taken').length)
const due = computed(() => data.value.classes.filter((c) => !['closed', 'none'].includes(c.state)))
const look = { taken: SOFT.present, partial: SOFT.partial, pending: SOFT.late }
</script>

<template>
    <section v-if="data && data.classes.length" class="rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-800 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-200">
        <div class="flex items-center justify-between gap-2">
            <h2 class="font-semibold">{{ data.words.title }}</h2>
            <Link :href="route('attendance.index')" class="text-xs font-medium text-brand-700 hover:underline dark:text-brand-300">{{ data.words.open }}</Link>
        </div>
        <p v-if="!due.length" class="mt-2 text-slate-500">{{ data.words.closed }}</p>
        <template v-else>
            <p class="mt-1 text-slate-500">{{ fill(data.words.progress, { done: n(done), all: n(due.length) }) }}</p>
            <ul class="mt-2 flex max-h-40 flex-wrap gap-1.5 overflow-y-auto">
                <li v-for="c in due" :key="c.id"><Link :href="route('attendance.index', { class: c.id })" class="badge" :class="look[c.state]" :title="data.words.state[c.state]">{{ c.state === 'taken' ? '✓' : '○' }} {{ c.label }}</Link></li>
            </ul>
        </template>
        <p v-if="data.low" class="mt-2"><Link :href="route('attendance.reports', { type: 'low', period: 'year' })" class="font-medium text-red-700 hover:underline dark:text-red-300">{{ fill(data.words.low, { n: n(data.low), t: n(data.threshold) }) }}</Link></p>
    </section>
</template>
