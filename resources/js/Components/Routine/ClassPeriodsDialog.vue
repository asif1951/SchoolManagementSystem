<script setup>
/**
 * The periods and breaks of ONE class: its own length of period, its own breaks. A quick builder makes the table
 * ("start 10:00, 40 minutes, 7 periods, a 30 minute break after the 4th"); the table can then be changed row by row.
 */
import { router } from '@inertiajs/vue3'
import { computed, reactive, ref } from 'vue'

const props = defineProps({
    classId: { type: Number, required: true },
    periods: { type: Array, default: () => [] },       // the periods the class uses now
    source: { type: String, default: 'common' },        // class | shift | common
    classes: { type: Array, default: () => [] },        // every class of the year, to copy the periods to
    errors: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['close'])

const rows = ref(props.periods.map((p) => ({ name: p.name, starts_at: p.start, ends_at: p.end, is_break: !!p.is_break })))

// ---- the builder ----------------------------------------------------------------------------------------------------
const b = reactive({ start: props.periods[0]?.start || '10:00', length: 40, count: props.periods.filter((p) => !p.is_break).length || 7, breakAfter: 4, breakLength: 30 })

const toMin = (t) => { const [h, m] = String(t).split(':').map(Number); return h * 60 + m }
const fmt = (n) => `${String(Math.floor(n / 60) % 24).padStart(2, '0')}:${String(n % 60).padStart(2, '0')}`
const ordinal = (n) => `${n}${[, 'st', 'nd', 'rd'][n % 100 > 10 && n % 100 < 14 ? 0 : n % 10] || 'th'}`

const build = () => {
    let t = toMin(b.start)
    const out = []

    for (let i = 1; i <= Number(b.count); i++) {
        out.push({ name: ordinal(i), starts_at: fmt(t), ends_at: fmt(t + Number(b.length)), is_break: false })
        t += Number(b.length)

        if (i === Number(b.breakAfter) && Number(b.breakLength) > 0 && i < Number(b.count)) {
            out.push({ name: 'Break', starts_at: fmt(t), ends_at: fmt(t + Number(b.breakLength)), is_break: true })
            t += Number(b.breakLength)
        }
    }

    rows.value = out
}

const addBreak = () => {
    const last = rows.value[rows.value.length - 1]
    const s = last ? last.ends_at : '12:00'
    rows.value.push({ name: 'Break', starts_at: s, ends_at: fmt(toMin(s) + 20), is_break: true })
}
const addPeriod = () => {
    const last = rows.value[rows.value.length - 1]
    const s = last ? last.ends_at : '10:00'
    rows.value.push({ name: ordinal(rows.value.filter((r) => !r.is_break).length + 1), starts_at: s, ends_at: fmt(toMin(s) + 40), is_break: false })
}
const remove = (i) => rows.value.splice(i, 1)
const move = (i, by) => { const j = i + by; if (j < 0 || j >= rows.value.length) return; const [r] = rows.value.splice(i, 1); rows.value.splice(j, 0, r) }
const renumber = () => { let n = 0; rows.value.forEach((r) => { if (!r.is_break) r.name = ordinal(++n) }) }

const lengths = computed(() => rows.value.filter((r) => !r.is_break).map((r) => toMin(r.ends_at) - toMin(r.starts_at)))
const summary = computed(() => {
    const t = rows.value.filter((r) => !r.is_break).length
    const br = rows.value.filter((r) => r.is_break)
    return `${t} periods` + (br.length ? ` · ${br.length} ${br.length === 1 ? 'break' : 'breaks'} (${br.map((r) => toMin(r.ends_at) - toMin(r.starts_at) + ' min').join(', ')})` : '') + (rows.value.length ? ` · ${rows.value[0].starts_at} to ${rows.value[rows.value.length - 1].ends_at}` : '')
})

// ---- also use for other classes ------------------------------------------------------------------------------------
const alsoFor = ref([])
const find = ref('')
const shown = computed(() => props.classes.filter((c) => c.id !== props.classId && (!find.value || c.label.toLowerCase().includes(find.value.toLowerCase()))))

const busy = ref(false)
const save = () => {
    busy.value = true
    router.put(route('routine.class-periods.save', props.classId), { periods: rows.value, also_for: alsoFor.value }, {
        preserveScroll: true, onSuccess: () => emit('close'), onFinish: () => { busy.value = false },
    })
}
const reset = () => router.delete(route('routine.class-periods.reset', props.classId), { preserveScroll: true, onSuccess: () => emit('close') })

const sourceText = computed(() => ({ class: 'This class has periods of its own.', shift: "This class uses the periods of its shift.", common: 'This class uses the common periods.' }[props.source]))
</script>

<template>
    <div class="space-y-5">
        <p class="text-sm text-slate-600 dark:text-slate-300">{{ sourceText }} Change the length of the periods and the breaks for this class only. Subjects already placed stay with their position (the 3rd period stays the 3rd).</p>

        <div v-if="errors.periods" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">{{ errors.periods }}</div>

        <fieldset class="rounded-md border border-slate-200 p-4 dark:border-slate-800">
            <legend class="px-1 text-sm font-semibold">Quick builder</legend>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
                <div><label class="field-label" for="b-start">First period starts</label><input id="b-start" v-model="b.start" type="time" class="field" /></div>
                <div><label class="field-label" for="b-len">Each period (min)</label><input id="b-len" v-model.number="b.length" type="number" min="10" max="120" class="field" /></div>
                <div><label class="field-label" for="b-count">Periods</label><input id="b-count" v-model.number="b.count" type="number" min="1" max="14" class="field" /></div>
                <div><label class="field-label" for="b-after">Break after period</label><input id="b-after" v-model.number="b.breakAfter" type="number" min="1" max="14" class="field" /></div>
                <div><label class="field-label" for="b-bl">Break (min)</label><input id="b-bl" v-model.number="b.breakLength" type="number" min="0" max="120" class="field" /></div>
            </div>
            <button type="button" class="btn mt-3" @click="build">Build the periods</button>
            <span class="ml-3 text-xs text-slate-500">This replaces the table below; you can still change any row.</span>
        </fieldset>

        <div>
            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm font-semibold">Periods and breaks <span class="font-normal text-slate-500">· {{ summary }}</span></p>
                <div class="flex gap-2"><button type="button" class="btn" @click="addPeriod">Add a period</button><button type="button" class="btn" @click="addBreak">Add a break</button><button type="button" class="btn" @click="renumber">Name 1st, 2nd…</button></div>
            </div>
            <div class="max-h-72 overflow-auto rounded-md border border-slate-200 dark:border-slate-800">
                <table class="data-table plain min-w-[520px]">
                    <thead><tr><th>Name</th><th>From</th><th>To</th><th>Min</th><th>Break</th><th class="num">Order</th></tr></thead>
                    <tbody>
                        <tr v-for="(r, i) in rows" :key="i" :class="r.is_break ? 'bg-slate-50 dark:bg-slate-800/40' : ''">
                            <td><input v-model="r.name" type="text" maxlength="40" class="field w-28" :aria-label="`Name of row ${i + 1}`" /></td>
                            <td><input v-model="r.starts_at" type="time" class="field w-28" :aria-label="`Start of row ${i + 1}`" /></td>
                            <td><input v-model="r.ends_at" type="time" class="field w-28" :aria-label="`End of row ${i + 1}`" /></td>
                            <td class="muted tabular-nums">{{ toMin(r.ends_at) - toMin(r.starts_at) }}</td>
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
        </div>

        <details class="rounded-md border border-slate-200 dark:border-slate-800">
            <summary class="cursor-pointer px-4 py-2.5 text-sm font-semibold">Use these periods for other classes too <span v-if="alsoFor.length" class="font-normal text-brand-700">· {{ alsoFor.length }} chosen</span></summary>
            <div class="border-t border-slate-200 p-4 dark:border-slate-800">
                <input v-model="find" type="search" class="field mb-2 max-w-xs" placeholder="Find a class" aria-label="Find a class" />
                <div class="max-h-44 space-y-1 overflow-auto">
                    <label v-for="c in shown" :key="c.id" class="flex items-center gap-2 text-sm"><input v-model="alsoFor" type="checkbox" :value="c.id" class="rounded border-slate-300 text-brand-700 focus:ring-brand-600" />{{ c.label }}</label>
                    <p v-if="!shown.length" class="text-sm text-slate-500">No class matches.</p>
                </div>
                <p class="mt-2 text-xs text-slate-500">Their own periods are replaced; what they already have placed stays in the same position.</p>
            </div>
        </details>

        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-200 pt-4 dark:border-slate-800">
            <button v-if="source === 'class'" type="button" class="btn" @click="reset">Use the shift's periods again</button>
            <span v-else></span>
            <div class="flex gap-2"><button type="button" class="btn" @click="emit('close')">Cancel</button><button type="button" class="btn btn-primary" :disabled="busy || !rows.length" @click="save">Save for this class</button></div>
        </div>
    </div>
</template>
