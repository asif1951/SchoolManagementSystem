<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, reactive, ref } from 'vue'

const props = defineProps({
    shifts: { type: Array, default: () => [] },
    sets: { type: Array, default: () => [] },
    days: { type: Array, default: () => [] },
    working: { type: Array, default: () => [] },
    ownClasses: { type: Array, default: () => [] },
    flash: { type: Object, default: () => ({}) },
    errors: { type: Object, default: () => ({}) },
})

const tab = ref('common')           // 'common' or a shift id
const setFor = (shiftId) => props.sets.find((s) => (s.shift_id ?? null) === shiftId)
const common = computed(() => setFor(null))

// an editable copy per set, started from what is saved (a shift without its own set starts from the common one)
const draft = reactive({})
const key = (shiftId) => String(shiftId ?? 'common')
const ensure = (shiftId) => {
    const k = key(shiftId)
    if (!draft[k]) {
        const own = setFor(shiftId)
        const from = own && own.own ? own.periods : (common.value?.periods || [])
        draft[k] = from.map((p) => ({ ...p }))
    }
    return draft[k]
}
ensure(null)

const current = computed(() => (tab.value === 'common' ? null : Number(tab.value)))
const rows = computed(() => ensure(current.value))
const started = reactive({})        // shifts the admin has just chosen to give their own periods
const usesCommon = computed(() => current.value !== null && !(setFor(current.value)?.own) && !started[current.value])

const add = () => {
    const last = rows.value[rows.value.length - 1]
    const start = last ? last.ends_at : '10:00'
    const end = addMinutes(start, 40)
    rows.value.push({ name: `${rows.value.filter((r) => !r.is_break).length + 1}th`, starts_at: start, ends_at: end, is_break: false })
}
const addBreak = () => {
    const last = rows.value[rows.value.length - 1]
    const start = last ? last.ends_at : '12:40'
    rows.value.push({ name: 'Break', starts_at: start, ends_at: addMinutes(start, 30), is_break: true })
}
const remove = (i) => rows.value.splice(i, 1)
const move = (i, by) => { const j = i + by; if (j < 0 || j >= rows.value.length) return; const [r] = rows.value.splice(i, 1); rows.value.splice(j, 0, r) }

function addMinutes(hhmm, mins) {
    const [h, m] = hhmm.split(':').map(Number)
    const t = Math.min(23 * 60 + 59, h * 60 + m + mins)
    return `${String(Math.floor(t / 60)).padStart(2, '0')}:${String(t % 60).padStart(2, '0')}`
}

// periods are named by position when the admin adds or reorders: keep ordinals tidy
const ordinal = (n) => `${n}${[, 'st', 'nd', 'rd'][n % 100 > 10 && n % 100 < 14 ? 0 : n % 10] || 'th'}`
const renumber = () => { let n = 0; rows.value.forEach((r) => { if (!r.is_break) r.name = ordinal(++n) }) }

const busy = ref(false)
const save = () => {
    busy.value = true
    router.put(route('routine.periods.save'), { shift_id: current.value, periods: rows.value.map((r) => ({ ...r, is_break: !!r.is_break })) }, { preserveScroll: true, preserveState: true, onFinish: () => { busy.value = false } })
}
const useCommon = () => router.delete(route('routine.periods.reset', current.value), { preserveScroll: true, onSuccess: () => { delete draft[key(current.value)] } })
const startOwn = () => { draft[key(current.value)] = (common.value?.periods || []).map((p) => ({ ...p })); started[current.value] = true }

const followAgain = (c) => router.delete(route('routine.class-periods.reset', c.id), { preserveScroll: true, preserveState: true })

const workingDays = ref([...props.working])
const saveDays = () => router.put(route('routine.days.save'), { days: workingDays.value }, { preserveScroll: true })

const shiftName = (id) => props.shifts.find((s) => s.id === id)?.name
</script>

<template>
    <Head title="Periods and days" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-slate-50 text-slate-800 dark:bg-slate-950 dark:text-slate-200">
            <div class="mx-auto max-w-4xl px-4 py-5 sm:px-6 lg:py-7">

                <nav class="mb-2 text-xs text-slate-500 dark:text-slate-400" aria-label="Breadcrumb">
                    <Link :href="route('routine.index')" class="hover:underline">Routine</Link> / <span class="font-medium text-slate-700 dark:text-slate-200">Periods and days</span>
                </nav>

                <header class="mb-5 border-b border-slate-200 pb-4 dark:border-slate-800">
                    <h1 class="text-xl font-semibold text-slate-900 dark:text-white">Periods and working days</h1>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">The bell schedule every routine is built on. Shifts that run at different times can have their own periods.</p>
                </header>

                <div v-if="flash.success" class="mb-4 rounded-md border border-l-4 border-slate-200 border-l-green-600 bg-white px-4 py-3 text-sm dark:border-slate-700 dark:bg-slate-900" role="status">{{ flash.success }}</div>
                <div v-if="errors.periods || errors.days" class="mb-4 rounded-md border border-l-4 border-slate-200 border-l-red-600 bg-white px-4 py-3 text-sm dark:border-slate-700 dark:bg-slate-900" role="alert">{{ errors.periods || errors.days }}</div>

                <!-- working days -->
                <section class="panel mb-5 p-4">
                    <h2 class="text-sm font-semibold">Working days</h2>
                    <p class="mt-0.5 text-xs text-slate-500">Only these days appear in a routine. This is the same setting as the weekly off days of the Academic Calendar.</p>
                    <div class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-2">
                        <label v-for="d in days" :key="d.id" class="inline-flex items-center gap-1.5 text-sm"><input v-model="workingDays" type="checkbox" :value="d.id" class="rounded border-slate-300 text-brand-700 focus:ring-brand-600" />{{ d.name }}</label>
                        <button type="button" class="btn btn-primary" :disabled="!workingDays.length" @click="saveDays">Save days</button>
                    </div>
                </section>

                <!-- classes this page does not change -->
                <section v-if="ownClasses.length" class="mb-5 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    <p class="font-semibold">{{ ownClasses.length }} {{ ownClasses.length === 1 ? 'class has' : 'classes have' }} periods of their own. Changes on this page do not reach them.</p>
                    <ul class="mt-2 max-h-48 space-y-1 overflow-auto">
                        <li v-for="c in ownClasses" :key="c.id" class="flex flex-wrap items-center justify-between gap-2">
                            <Link :href="route('routine.editor', c.id)" class="underline">{{ c.label }} · {{ c.year }}</Link>
                            <button type="button" class="font-medium text-brand-700 hover:underline" @click="followAgain(c)">Follow this page again</button>
                        </li>
                    </ul>
                </section>

                <!-- periods -->
                <section class="panel overflow-hidden">
                    <div class="flex flex-wrap gap-1 border-b border-slate-200 px-3 pt-2 dark:border-slate-800" role="tablist">
                        <button type="button" role="tab" :aria-selected="tab === 'common'" class="-mb-px border-b-2 px-3 py-2 text-sm font-medium" :class="tab === 'common' ? 'border-brand-700 text-brand-700' : 'border-transparent text-slate-600 hover:text-slate-900'" @click="tab = 'common'">All shifts</button>
                        <button v-for="s in shifts" :key="s.id" type="button" role="tab" :aria-selected="tab === String(s.id)" class="-mb-px border-b-2 px-3 py-2 text-sm font-medium" :class="tab === String(s.id) ? 'border-brand-700 text-brand-700' : 'border-transparent text-slate-600 hover:text-slate-900'" @click="tab = String(s.id)">
                            {{ s.name }}<span v-if="setFor(s.id)?.own || started[s.id]" class="ml-1 text-[10px] uppercase text-brand-600">own</span>
                        </button>
                    </div>

                    <div class="p-4">
                        <div v-if="usesCommon" class="mb-4 rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600 dark:border-slate-800 dark:bg-slate-800/40 dark:text-slate-300">
                            {{ shiftName(current) }} uses the periods of <b>All shifts</b>. If it runs at different times, give it a schedule of its own.
                            <button type="button" class="ml-1 font-medium text-brand-700 hover:underline" @click="startOwn">Give {{ shiftName(current) }} its own periods</button>
                        </div>

                        <template v-else>
                            <div class="overflow-x-auto">
                                <table class="data-table plain min-w-[560px]">
                                    <thead><tr><th class="w-8">#</th><th>Name</th><th>From</th><th>To</th><th>Break</th><th class="num">Order</th></tr></thead>
                                    <tbody>
                                        <tr v-for="(r, i) in rows" :key="i" :class="r.is_break ? 'bg-slate-50 dark:bg-slate-800/40' : ''">
                                            <td class="muted tabular-nums">{{ i + 1 }}</td>
                                            <td><input v-model="r.name" type="text" maxlength="40" class="field w-32" :aria-label="`Name of row ${i + 1}`" /></td>
                                            <td><input v-model="r.starts_at" type="time" class="field w-32" :aria-label="`Start of row ${i + 1}`" /></td>
                                            <td><input v-model="r.ends_at" type="time" class="field w-32" :aria-label="`End of row ${i + 1}`" /></td>
                                            <td><input v-model="r.is_break" type="checkbox" class="rounded border-slate-300 text-brand-700 focus:ring-brand-600" :aria-label="`Row ${i + 1} is a break`" /></td>
                                            <td class="num whitespace-nowrap">
                                                <button type="button" class="px-1 text-slate-400 hover:text-slate-800" :disabled="i === 0" aria-label="Move up" @click="move(i, -1)">↑</button>
                                                <button type="button" class="px-1 text-slate-400 hover:text-slate-800" :disabled="i === rows.length - 1" aria-label="Move down" @click="move(i, 1)">↓</button>
                                                <button type="button" class="ml-2 text-sm font-medium text-red-700 hover:underline" @click="remove(i)">Remove</button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" class="btn" @click="add">Add a period</button>
                                    <button type="button" class="btn" @click="addBreak">Add a break</button>
                                    <button type="button" class="btn" @click="renumber">Name periods 1st, 2nd…</button>
                                </div>
                                <div class="flex gap-2">
                                    <button v-if="current !== null" type="button" class="btn" @click="useCommon">Use the common periods</button>
                                    <button type="button" class="btn btn-primary" :disabled="busy" @click="save">Save periods</button>
                                </div>
                            </div>
                            <p class="mt-3 text-xs text-slate-500">Removing a period also removes it from every routine that used it. Changing times keeps the subjects in place.</p>
                        </template>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
