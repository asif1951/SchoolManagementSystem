<script setup>
/**
 * What the calendar says, on other screens: whether today is a school day, the next holiday and coming dates.
 * `types` narrows it (fee due dates on the fee screen); `todayOnly` shows nothing unless there is no school today.
 */
import { Link, usePage } from '@inertiajs/vue3'
import { computed, onMounted, ref } from 'vue'
import { label, range } from '@/lib/calendar'

const props = defineProps({ types: { type: String, default: '' }, title: { type: String, default: 'School calendar' }, todayOnly: Boolean })
const page = usePage()
const allowed = computed(() => { const a = page.props.auth?.abilities ?? []; return a.includes('*') || a.some((x) => x.startsWith('calendar.')) })
const data = ref(null)

onMounted(async () => {
    if (!allowed.value) return
    try { data.value = (await window.axios.get(route('calendar.upcoming'), { params: { types: props.types || undefined } })).data }
    catch { data.value = null }          // the screen works without this box
})
const closed = computed(() => data.value && !data.value.now.working)
</script>

<template>
    <div v-if="data && todayOnly">
        <p v-if="closed" class="mb-3 rounded-md border border-red-300 bg-red-50 px-4 py-2.5 text-sm font-medium text-red-900" role="status">
            No school today: {{ data.now.reason }}. <Link :href="route('calendar.index')" class="underline">Calendar</Link>
        </p>
        <p v-else-if="data.now.type === 'makeup'" class="mb-3 rounded-md border border-teal-300 bg-teal-50 px-4 py-2.5 text-sm font-medium text-teal-900" role="status">Today is a make-up day.</p>
    </div>

    <section v-else-if="data && (data.events.length || data.holiday || (closed && !types))" class="rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-800 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-200">
        <div class="flex items-center justify-between gap-2">
            <h2 class="font-semibold">{{ title }}</h2>
            <Link :href="route('calendar.index')" class="text-xs font-medium text-brand-700 hover:underline dark:text-brand-300">Open calendar</Link>
        </div>
        <p v-if="closed && !types" class="mt-2 rounded bg-red-50 px-2 py-1 font-medium text-red-900">No school today: {{ data.now.reason }}</p>
        <p v-if="data.holiday" class="mt-2">Next holiday: <b>{{ data.holiday.title }}</b> · {{ label(data.holiday.starts_on, 'both', false) }}
            <span class="text-slate-500">({{ data.holiday.in_days === 0 ? 'now' : `in ${data.holiday.in_days} ${data.holiday.in_days === 1 ? 'day' : 'days'}` }})</span></p>
        <ul class="mt-2 divide-y divide-slate-100 dark:divide-slate-800">
            <li v-for="e in data.events" :key="`${e.id}-${e.starts_on}`" class="flex flex-wrap justify-between gap-x-3 py-1.5">
                <span class="min-w-0 truncate">{{ e.title }}<span v-if="e.tentative" class="ml-1 text-xs text-amber-700">(tentative)</span></span>
                <span class="text-slate-500">{{ range(e.starts_on, e.ends_on, 'english') }}</span>
            </li>
        </ul>
    </section>
</template>
