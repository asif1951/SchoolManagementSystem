<script setup>
/**
 * Make the routine of one class by itself. The admin says how many periods a week each subject gets and the most it may
 * have in one day; the system places them using the subject teachers of the class module and never puts a teacher in
 * two places at once. A preview comes first; nothing changes until "Use this routine".
 */
import { router } from '@inertiajs/vue3'
import { computed, reactive, ref } from 'vue'

const props = defineProps({
    classId: { type: Number, required: true },
    plan: { type: Object, required: true },            // { rows: [{subject, teacher, periods_per_week, max_per_day}], slots, saved }
    grid: { type: Object, required: true },             // days and periods, to draw the preview
})

const emit = defineEmits(['close'])

const rows = reactive(props.plan.rows.map((r) => ({ ...r })))
const keep = ref(false)
const tmax = ref('')
const preview = ref(null)
const loading = ref(false)
const error = ref('')
const copyToSections = ref(false)

const slots = computed(() => props.plan.slots)
const total = computed(() => rows.reduce((a, r) => a + (Number(r.periods_per_week) || 0), 0))
const left = computed(() => slots.value - total.value)
const noTeacher = computed(() => rows.filter((r) => r.periods_per_week > 0 && !r.teacher_id).map((r) => r.subject))
const days = computed(() => props.grid.days.length)

const suggest = () => {
    const n = rows.length || 1
    const base = Math.floor(slots.value / n)
    let extra = slots.value - base * n
    rows.forEach((r) => {
        r.periods_per_week = base + (extra-- > 0 ? 1 : 0)
        r.max_per_day = r.periods_per_week > days.value ? 2 : 1
    })
    preview.value = null
}

const payload = (seed = null) => ({
    plan: rows.map((r) => ({ subject: r.subject, periods_per_week: Number(r.periods_per_week) || 0, max_per_day: Number(r.max_per_day) || 1 })),
    keep_existing: keep.value, teacher_max_per_day: tmax.value || null, seed,
})

const generate = async (newSeed = false) => {
    loading.value = true
    error.value = ''

    try {
        const res = await window.axios.post(route('routine.auto.preview', props.classId), payload(newSeed ? Math.floor(Math.random() * 2000000000) + 1 : (preview.value?.seed ?? null)))
        preview.value = res.data
    } catch (e) {
        preview.value = null
        error.value = e.response?.data?.message || 'The routine could not be made.'
    } finally {
        loading.value = false
    }
}

const result = computed(() => preview.value?.result || null)
const cell = (d, p) => result.value?.cells?.[`${d.id}-${p.id}`] || null

const apply = () => router.post(route('routine.auto.apply', props.classId), { seed: preview.value.seed, keep_existing: keep.value, teacher_max_per_day: tmax.value || null }, { preserveScroll: true, onSuccess: () => emit('close') })
const savePlan = () => router.put(route('routine.plan.save', props.classId), { ...payload(), copy_to_sections: copyToSections.value }, { preserveScroll: true })
</script>

<template>
    <div class="space-y-5">
        <p class="text-sm text-slate-600 dark:text-slate-300">Tell the system how often each subject should appear in the week. It places them so that no teacher is in two classes at the same time. You can change any period by hand afterwards.</p>

        <div>
            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm font-semibold">Weekly plan <span class="font-normal text-slate-500">· the week has {{ slots }} periods ({{ days }} days)</span></p>
                <button type="button" class="btn" @click="suggest">Share the week evenly</button>
            </div>
            <div class="overflow-x-auto rounded-md border border-slate-200 dark:border-slate-800">
                <table class="data-table plain min-w-[520px]">
                    <thead><tr><th>Subject</th><th>Teacher</th><th class="w-32">Periods a week</th><th class="w-32">Most in a day</th></tr></thead>
                    <tbody>
                        <tr v-for="r in rows" :key="r.subject">
                            <td class="font-medium text-slate-900 dark:text-white">{{ r.subject }}</td>
                            <td>{{ r.teacher || '' }}<span v-if="!r.teacher" class="text-amber-700">none assigned</span><span v-else-if="(r.teachers || []).length > 1" class="ml-1 text-xs text-slate-400">(shared)</span></td>
                            <td><input v-model.number="r.periods_per_week" type="number" min="0" max="40" class="field w-24" :aria-label="`Periods a week for ${r.subject}`" @input="preview = null" /></td>
                            <td><input v-model.number="r.max_per_day" type="number" min="1" max="8" class="field w-24" :aria-label="`Most in a day for ${r.subject}`" @input="preview = null" /></td>
                        </tr>
                        <tr v-if="!rows.length"><td colspan="4" class="px-4 py-6 text-center text-slate-500">This class has no subjects yet.</td></tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-sm" :class="left < 0 ? 'text-red-700' : 'text-slate-600 dark:text-slate-300'">
                {{ total }} of {{ slots }} periods planned<template v-if="left > 0"> · {{ left }} will stay empty</template><template v-if="left < 0"> · {{ -left }} too many: lower some numbers</template>
            </p>
            <p v-if="noTeacher.length" class="mt-1 text-sm text-amber-800">No teacher is assigned to {{ noTeacher.join(', ') }}: those periods are placed without a teacher. <a href="/subject-teachers" class="font-medium underline">Assign teachers</a></p>
        </div>

        <div class="flex flex-wrap items-center gap-x-6 gap-y-3 text-sm">
            <label class="inline-flex items-center gap-2"><input v-model="keep" type="checkbox" class="rounded border-slate-300 text-brand-700 focus:ring-brand-600" @change="preview = null" />Keep the periods I have already placed</label>
            <label class="inline-flex items-center gap-2">A teacher teaches at most <input v-model="tmax" type="number" min="1" max="12" class="field w-16" aria-label="Most periods a teacher teaches in a day" @input="preview = null" /> periods a day <span class="text-slate-400">(optional)</span></label>
        </div>

        <div v-if="error" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">{{ error }}</div>

        <div class="flex flex-wrap items-center gap-2">
            <button type="button" class="btn btn-primary" :disabled="loading || !rows.length || left < 0" @click="generate(false)">{{ loading ? 'Making it…' : (preview ? 'Make it again' : 'Make the routine') }}</button>
            <button v-if="preview" type="button" class="btn" :disabled="loading" @click="generate(true)">Try another arrangement</button>
            <button type="button" class="btn" @click="savePlan">Save the plan</button>
            <label class="inline-flex items-center gap-1.5 text-sm text-slate-600"><input v-model="copyToSections" type="checkbox" class="rounded border-slate-300 text-brand-700 focus:ring-brand-600" />also for the other sections of this class</label>
        </div>

        <!-- the result, before anything is saved -->
        <section v-if="result" class="rounded-md border border-slate-200 dark:border-slate-800">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-2.5 dark:border-slate-800">
                <p class="text-sm font-semibold">Preview <span class="font-normal text-slate-500">· {{ result.placed + result.kept }} of {{ result.needed }} placed</span></p>
                <span class="badge" :class="result.unplaced.length || result.error ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-green-200 bg-green-50 text-green-800'">{{ result.unplaced.length || result.error ? 'Not everything fits' : 'Everything fits' }}</span>
            </div>

            <p v-if="result.error" class="px-4 py-3 text-sm text-red-800" role="alert">{{ result.error }}</p>

            <div v-else class="overflow-x-auto">
                <table class="plain w-full border-collapse text-xs" style="min-width: 720px">
                    <thead><tr class="bg-slate-50 dark:bg-slate-900">
                        <th class="w-24 border-b border-r border-slate-200 px-2 py-1.5 text-left font-semibold text-slate-500 dark:border-slate-800">Day</th>
                        <th v-for="p in grid.periods" :key="p.id" class="border-b border-slate-200 px-1 py-1.5 text-center font-semibold text-slate-500 dark:border-slate-800" :class="p.is_break ? 'w-8 bg-slate-100' : ''">{{ p.is_break ? '' : p.name }}</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="d in grid.days" :key="d.id" class="border-b border-slate-100 last:border-0 dark:border-slate-800">
                            <th class="border-r border-slate-200 px-2 py-1.5 text-left font-medium text-slate-700 dark:border-slate-800 dark:text-slate-200">{{ d.short }}</th>
                            <template v-for="p in grid.periods" :key="p.id">
                                <td v-if="p.is_break" class="bg-slate-100 dark:bg-slate-800/60"></td>
                                <td v-else class="px-1 py-1.5 text-center">
                                    <div v-if="cell(d, p)" class="leading-tight" :class="cell(d, p).kept ? 'italic text-slate-500' : 'text-slate-900 dark:text-white'">
                                        <div class="font-medium">{{ cell(d, p).subject }}</div>
                                        <div class="text-[10px] text-slate-400">{{ cell(d, p).teacher }}</div>
                                    </div>
                                    <span v-else class="text-slate-300">·</span>
                                </td>
                            </template>
                        </tr>
                    </tbody>
                </table>
            </div>

            <ul v-if="result.unplaced.length" class="space-y-1 border-t border-slate-200 px-4 py-3 text-sm dark:border-slate-800">
                <li v-for="u in result.unplaced" :key="u.subject" class="text-amber-800"><b>{{ u.subject }}</b>: {{ u.missing }} {{ u.missing === 1 ? 'period' : 'periods' }} could not be placed because {{ u.why }}.</li>
            </ul>
            <p v-if="result.free_left > 0 && !result.error" class="border-t border-slate-200 px-4 py-2 text-xs text-slate-500 dark:border-slate-800">{{ result.free_left }} periods stay empty. Raise some subjects' numbers to fill them.</p>
        </section>

        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-200 pt-4 dark:border-slate-800">
            <p class="text-xs text-slate-500">{{ keep ? 'Your placed periods stay; the rest are added.' : 'The draft of this class is replaced. The published copy is not touched until you publish.' }}</p>
            <div class="flex gap-2"><button type="button" class="btn" @click="emit('close')">Close</button><button type="button" class="btn btn-primary" :disabled="!result || result.error || !(result.placed + result.kept)" @click="apply">Use this routine</button></div>
        </div>
    </div>
</template>
