<script setup>
/** Add or change one date. For a yearly event the change can be for every year or only the one clicked. */
import Dialog from '@/Components/Teaching/Dialog.vue'
import DateField from '@/Components/Calendar/DateField.vue'
import { router } from '@inertiajs/vue3'
import { computed, reactive, ref, watch } from 'vue'
import { WEEKDAYS, WEEK_ORDER, diffDays, addDays, range, weekday } from '@/lib/calendar'

const props = defineProps({
    event: { type: Object, default: null }, start: { type: String, default: '' }, end: { type: String, default: '' },
    types: Array, optionalOff: Array, classes: Array, exams: Array, weekdays: Array, can: Object,
})
const emit = defineEmits(['close'])

const editing = !!props.event
const e = props.event
const form = reactive({
    type: e?.type ?? 'holiday', title: e?.title ?? '', title_bn: e?.title_bn ?? '', starts_on: e?.starts_on ?? props.start, ends_on: e?.ends_on ?? props.end ?? props.start,
    audience: e?.audience ?? 'all', class_ids: [...(e?.class_ids ?? [])], note: e?.note ?? '', off: e?.off ?? false, tentative: e?.tentative ?? false,
    repeat: e?.repeat ?? 'none', follows_day: e?.follows_day ?? null, exam_id: e?.exam_id ?? null,
})
const repeating = editing && e.repeat !== 'none'
const year = repeating ? Number(e.starts_on.slice(0, 4)) : null
const scope = ref(repeating ? 'year' : 'all')

const editable = computed(() => props.can.manage || (props.can.close && ['closure', 'makeup'].includes(form.type)))
const choices = computed(() => props.types.filter((x) => props.can.manage || ['closure', 'makeup'].includes(x.key)))
// moving the start keeps the length
watch(() => form.starts_on, (now, before) => { if (now && before && form.ends_on) { const len = Math.max(0, diffDays(before, form.ends_on)); form.ends_on = addDays(now, len) } else if (now && !form.ends_on) form.ends_on = now })
watch(() => form.exam_id, (id) => { const x = props.exams.find((y) => y.id === id); if (x && !form.title) form.title = x.name })

const errors = ref({})
const busy = ref(false)
const options = { preserveScroll: true, preserveState: true, onSuccess: () => emit('close'), onError: (x) => { errors.value = x }, onFinish: () => { busy.value = false } }
const save = () => {
    busy.value = true; errors.value = {}
    const data = { ...form, ends_on: form.ends_on || form.starts_on, follows_day: form.follows_day === '' ? null : form.follows_day }
    if (!editing) return router.post(route('calendar.events.store'), data, options)
    // changing every year of a yearly event keeps the year it started in
    if (repeating && scope.value === 'all') { data.starts_on = e.starts_on; data.ends_on = e.ends_on }
    if (repeating && scope.value === 'year') data.year = year
    router.put(route('calendar.events.update', e.id), data, options)
}
const removing = ref(false)
const remove = () => router.delete(route('calendar.events.destroy', e.id), { ...options, data: repeating && scope.value === 'year' ? { year } : {} })
const confirmDate = () => router.post(route('calendar.events.confirm', e.id), { starts_on: form.starts_on, ends_on: form.ends_on }, options)
</script>

<template>
    <Dialog :title="editing ? (editable ? 'Edit date' : 'Details') : 'Add a date'" wide @close="emit('close')">
        <!-- someone who may only look -->
        <div v-if="editing && !editable" class="space-y-2">
            <p class="text-lg font-semibold">{{ e.title }}<span v-if="e.title_bn" class="block text-base font-normal">{{ e.title_bn }}</span></p>
            <p>{{ range(e.starts_on, e.ends_on, 'both', true) }}</p>
            <p>{{ types.find((x) => x.key === e.type)?.label }}<span v-if="e.tentative"> · tentative</span><span v-if="e.off"> · no school</span></p>
            <p v-if="e.note">{{ e.note }}</p>
        </div>

        <form v-else class="space-y-3" @submit.prevent="save">
            <ul v-if="Object.keys(errors).length" class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-red-800" role="alert"><li v-for="(m, k) in errors" :key="k">{{ m }}</li></ul>

            <fieldset v-if="repeating" class="rounded-md border border-brand-300 bg-brand-50 px-3 py-2">
                <legend class="px-1 text-xs font-semibold">This date repeats every year ({{ e.repeat === 'bangla' ? 'by Bangla date' : 'by English date' }})</legend>
                <label class="mr-4 inline-flex items-center gap-1.5"><input v-model="scope" type="radio" value="year" />Only {{ year }}</label>
                <label class="inline-flex items-center gap-1.5"><input v-model="scope" type="radio" value="all" />Every year</label>
                <p v-if="scope === 'all'" class="mt-1 text-xs text-slate-600">The dates below are not changed for every year; edit titles and settings here, or choose “Only {{ year }}” to move it.</p>
            </fieldset>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div><label class="field-label" for="ev-type">Type</label><select id="ev-type" v-model="form.type" class="field"><option v-for="x in choices" :key="x.key" :value="x.key">{{ x.label }}</option></select></div>
                <div v-if="form.type === 'exam' && exams.length"><label class="field-label" for="ev-exam">Exam (optional)</label><select id="ev-exam" v-model="form.exam_id" class="field"><option :value="null">Not linked</option><option v-for="x in exams" :key="x.id" :value="x.id">{{ x.name }}</option></select></div>
                <div><label class="field-label" for="ev-title">Title (English)</label><input id="ev-title" v-model="form.title" required maxlength="150" class="field" /></div>
                <div><label class="field-label" for="ev-title-bn">Title (বাংলা)</label><input id="ev-title-bn" v-model="form.title_bn" maxlength="150" class="field" lang="bn" /></div>
                <DateField id="ev-start" v-model="form.starts_on" label="From" required />
                <DateField id="ev-end" v-model="form.ends_on" label="To" :min="form.starts_on" />
            </div>

            <div v-if="form.type === 'makeup'"><label class="field-label" for="ev-follows">Which weekday’s routine runs?</label>
                <select id="ev-follows" v-model="form.follows_day" class="field sm:w-64"><option :value="null">{{ weekdays.includes(weekday(form.starts_on || '2026-01-01')) ? 'That day’s own' : 'Choose a weekday' }}</option><option v-for="d in WEEK_ORDER.filter((x) => weekdays.includes(x))" :key="d" :value="d">{{ WEEKDAYS[d] }}</option></select></div>

            <fieldset>
                <legend class="field-label">Who is it for?</legend>
                <label class="mr-4 inline-flex items-center gap-1.5"><input v-model="form.audience" type="radio" value="all" />Whole school</label>
                <label class="mr-4 inline-flex items-center gap-1.5"><input v-model="form.audience" type="radio" value="classes" />Chosen classes</label>
                <label class="inline-flex items-center gap-1.5"><input v-model="form.audience" type="radio" value="teachers" />Teachers only</label>
                <div v-if="form.audience === 'classes'" class="mt-2 grid max-h-36 grid-cols-2 gap-1 overflow-auto rounded-md border border-slate-200 p-2 sm:grid-cols-3">
                    <label v-for="c in classes" :key="c.id" class="flex items-center gap-1.5 text-sm"><input v-model="form.class_ids" type="checkbox" :value="c.id" />{{ c.label }}</label>
                    <p v-if="!classes.length" class="text-slate-500">No class in this year.</p>
                </div>
            </fieldset>

            <div class="flex flex-wrap items-center gap-x-5 gap-y-2">
                <label class="flex items-center gap-1.5">Repeat
                    <select v-model="form.repeat" class="field !w-auto" :disabled="form.audience === 'classes' || ['closure', 'makeup', 'exam'].includes(form.type) || (repeating && scope === 'year')">
                        <option value="none">No</option><option value="english">Every year (English date)</option><option value="bangla">Every year (Bangla date)</option></select></label>
                <label v-if="optionalOff.includes(form.type) && form.audience !== 'teachers'" class="flex items-center gap-1.5"><input v-model="form.off" type="checkbox" />No school that day</label>
                <label class="flex items-center gap-1.5"><input v-model="form.tentative" type="checkbox" />Tentative (depends on the moon, or not final)</label>
            </div>

            <div><label class="field-label" for="ev-note">Note</label><textarea id="ev-note" v-model="form.note" rows="2" maxlength="500" class="field"></textarea></div>

            <div class="flex flex-wrap items-center gap-2 border-t border-slate-200 pt-3 dark:border-slate-800">
                <template v-if="editing">
                    <button v-if="!removing" type="button" class="text-sm font-medium text-red-700 hover:underline" @click="removing = true">Delete</button>
                    <template v-else><span class="text-sm">Delete {{ repeating ? (scope === 'year' ? `${year} only` : 'every year') : 'this date' }}?</span><button type="button" class="btn btn-danger" @click="remove">Yes, delete</button><button type="button" class="btn" @click="removing = false">No</button></template>
                    <button v-if="e.tentative && can.manage && !repeating" type="button" class="btn" @click="confirmDate">Confirm these dates</button>
                </template>
                <span class="flex-1"></span>
                <button type="button" class="btn" @click="emit('close')">Cancel</button>
                <button class="btn btn-primary" :disabled="busy || !form.title || !form.starts_on">{{ busy ? 'Saving…' : 'Save' }}</button>
            </div>
        </form>
    </Dialog>
</template>
