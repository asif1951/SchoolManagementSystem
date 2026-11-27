<script setup>
/**
 * One class of the day. Tapping how much was covered saves the class at once (topic already chosen); everything else is
 * optional and saves itself. The screen changes immediately and confirms when the server has it.
 */
import { computed, onBeforeUnmount, reactive, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import { useT } from '@/composables/useT'

const props = defineProps({
    card: { type: Object, required: true },
    date: { type: String, required: true },
    items: { type: Array, default: () => [] },
    teachers: { type: Array, default: () => [] },
    disabled: { type: Boolean, default: false },
    siblings: { type: Array, default: () => [] },        // other unlogged classes of the same subject and level
})
const emit = defineEmits(['saved', 'removed', 'copied'])
const t = useT('teaching')

const remembered = () => { try { return JSON.parse(localStorage.getItem('teaching.log.defaults') || '{}') } catch { return {} } }
const remember = () => { try { localStorage.setItem('teaching.log.defaults', JSON.stringify({ understanding: form.understanding, homework: form.homework })) } catch { /* private window */ } }

const fresh = () => {
    const l = props.card.log
    if (l) return { ...l }
    const d = remembered()
    return { id: null, held: true, not_held_reason: null, coverage: null, understanding: d.understanding || 'good', homework: !!d.homework, next_plan: '', note: '',
        item_id: props.card.suggest.item_id, topic_id: props.card.suggest.topic_id, substitute_id: null }
}
const form = reactive(fresh())
const state = ref(props.card.log ? 'saved' : 'idle')       // idle | saving | saved | error
const message = ref('')
const more = ref(false)
const notHeld = ref(false)
const reasons = ['holiday', 'absent', 'event', 'exam', 'other']

// "held" recorded by attendance: nobody has said yet how much was covered (a teacher's own entry always says how it went)
const unmarked = computed(() => !!form.id && form.held && form.coverage === 0 && !form.understanding)

const topics = computed(() => props.items.find((i) => i.id === form.item_id)?.topics ?? [])
const choice = computed({
    get: () => (form.item_id ? `${form.item_id}:${form.topic_id ?? ''}` : ''),
    set: (v) => { const [i, tp] = v.split(':'); form.item_id = i ? Number(i) : null; form.topic_id = tp ? Number(tp) : null },
})

let timer = null
let running = null
let again = false
const push = async () => {
    clearTimeout(timer)
    timer = null
    if (running) { again = true; return running }        // one request at a time; the newest values follow
    state.value = 'saving'
    message.value = ''
    running = window.axios.put(route('teaching.log.save'), {
        id: form.id, log_date: props.date, class_id: props.card.class_id, subject_id: props.card.subject_id, teacher_id: props.card.teacher_id, period_id: props.card.period_id,
        item_id: form.item_id, topic_id: form.topic_id, held: form.held, not_held_reason: form.not_held_reason, coverage: form.coverage ?? 0,
        understanding: form.understanding, homework: form.homework, next_plan: form.next_plan || null, note: form.note || null, substitute_id: form.substitute_id || null,
    }).then((res) => {
        form.id = res.data.log.id
        state.value = 'saved'
        emit('saved', props.card, res.data)
        return res.data
    }).catch((e) => {
        state.value = 'error'
        message.value = e.response?.data?.errors ? Object.values(e.response.data.errors)[0][0] : (e.response?.status === 403 ? t('msg.not_allowed') : t('msg.not_saved'))
        again = false
    }).finally(() => {
        running = null
        if (again) { again = false; push() }
    })
    return running
}
const later = () => { clearTimeout(timer); if (form.id) timer = setTimeout(push, 700) }
// something typed a moment ago is still saved when the page is left
onBeforeUnmount(() => { if (timer) push() })

const cover = (value) => { form.understanding ||= remembered().understanding || 'good'; form.held = true; form.not_held_reason = null; form.coverage = value; notHeld.value = false; remember(); push() }
const missed = (reason) => { form.held = false; form.not_held_reason = t(`reason.${reason}`); form.coverage = 0; notHeld.value = false; push() }
const set = (field, value) => { form[field] = value; remember(); if (form.id) push() }
const copyLast = () => {
    const p = props.card.previous
    Object.assign(form, { understanding: p.understanding || form.understanding, homework: p.homework, next_plan: p.next_plan || '', item_id: p.coverage >= 100 ? form.item_id : p.item_id, topic_id: p.coverage >= 100 ? form.topic_id : p.topic_id })
    cover(p.coverage || 100)
}
const remove = async () => {
    clearTimeout(timer)
    try {
        const res = await window.axios.delete(route('teaching.log.destroy', form.id))
        const removed = props.card.log
        Object.assign(form, { ...fresh(), id: null, coverage: null, held: true, not_held_reason: null })
        state.value = 'idle'
        emit('removed', props.card, res.data, removed)
    } catch (e) { state.value = 'error'; message.value = e.response?.data?.errors ? Object.values(e.response.data.errors)[0][0] : t('msg.failed') }
}
const sameForAll = async () => {
    if (!form.id) return
    state.value = 'saving'
    try {
        const res = await window.axios.post(route('teaching.log.copy', form.id), { targets: props.siblings.map((s) => ({ class_id: s.class_id, period_id: s.period_id })) })
        state.value = 'saved'
        emit('copied', res.data.saved)
    } catch (e) { state.value = 'error'; message.value = e.response?.data?.errors ? Object.values(e.response.data.errors)[0][0] : t('msg.failed') }
}
const logged = computed(() => form.coverage !== null && (form.id !== null || state.value !== 'idle'))
const coverages = [{ v: 100, k: 'completed', on: 'border-green-700 bg-green-700 text-white' }, { v: 50, k: 'partly', on: 'border-amber-600 bg-amber-500 text-slate-900' }, { v: 0, k: 'not_taken', on: 'border-slate-700 bg-slate-700 text-white' }]
</script>

<template>
    <article class="panel overflow-hidden border-l-4" :class="!logged ? '!border-l-slate-300' : form.held ? '!border-l-green-600' : '!border-l-amber-500'" :aria-label="`${card.subject} ${card.class}`">
        <div class="flex items-start justify-between gap-2 px-3 pt-3">
            <div class="min-w-0">
                <h2 class="truncate text-base font-semibold text-slate-900 dark:text-white">{{ card.subject }}</h2>
                <p class="truncate text-sm text-slate-500">{{ card.class }}<template v-if="card.period"> · {{ card.period }} · {{ card.time }}</template><template v-else> · {{ t('log.extra') }}</template></p>
                <p v-if="card.covering" class="text-xs font-semibold text-brand-700">{{ t('log.covering') }}</p>
            </div>
            <span class="shrink-0 text-xs font-semibold" :class="state === 'error' ? 'text-red-700' : state === 'saved' ? 'text-green-700' : 'text-slate-400'" role="status" aria-live="polite">
                {{ state === 'saving' ? t('log.saving') : state === 'saved' ? `✓ ${t('log.saved')}` : state === 'error' ? t('log.not_saved') : '' }}
            </span>
        </div>

        <div class="space-y-3 p-3">
            <p v-if="message" class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">{{ message }} <button type="button" class="font-semibold underline" @click="push">{{ t('log.retry') }}</button></p>

            <template v-if="card.plan">
                <select v-model="choice" class="field" :disabled="disabled" :aria-label="t('topic')" @change="form.id && push()">
                    <option value="">{{ t('log.no_topic') }}</option>
                    <optgroup v-for="i in items" :key="i.id" :label="`${i.chapter} (${Math.round(i.progress)}%)`">
                        <option :value="`${i.id}:`">{{ i.chapter }}{{ i.topics.length ? ` — ${t('log.whole_chapter')}` : '' }}</option>
                        <option v-for="tp in i.topics" :key="tp.id" :value="`${i.id}:${tp.id}`">{{ tp.best >= 100 ? '✓ ' : '' }}{{ tp.name }}</option>
                    </optgroup>
                </select>
            </template>
            <p v-else class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">{{ t('log.no_plan') }} <Link :href="route('teaching.plans')" class="font-semibold text-brand-700 underline">{{ t('log.make_plan') }}</Link></p>

            <p v-if="!form.held" class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm font-medium text-amber-900">{{ t('log.not_held') }}: {{ form.not_held_reason }}</p>

            <p v-if="unmarked" class="rounded-md border border-brand-300 bg-brand-50 px-3 py-2 text-sm font-medium text-brand-900 dark:bg-slate-800 dark:text-brand-100">{{ t('log.held_by_attendance') }}</p>

            <div class="grid grid-cols-3 gap-2" role="group" :aria-label="t('log.how_much')">
                <button v-for="c in coverages" :key="c.k" type="button" class="min-h-[2.75rem] rounded-md border-2 px-1 text-sm font-semibold transition-colors disabled:opacity-50"
                        :class="form.held && form.coverage === c.v && logged && !unmarked ? c.on : 'border-slate-300 bg-white text-slate-700 hover:border-brand-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200'"
                        :aria-pressed="form.held && form.coverage === c.v && logged && !unmarked" :disabled="disabled" @click="cover(c.v)">{{ t(`coverage.${c.k}`) }}</button>
            </div>

            <div v-if="logged && form.held" class="flex flex-wrap items-center gap-2">
                <button v-for="u in ['good', 'average', 'revision']" :key="u" type="button" class="min-h-[2.25rem] rounded-full border px-3 text-sm" :class="form.understanding === u ? 'border-brand-700 bg-brand-700 text-white' : 'border-slate-300 text-slate-700 dark:text-slate-200'" :aria-pressed="form.understanding === u" :disabled="disabled" @click="set('understanding', u)">{{ t(`understanding.${u}`) }}</button>
                <button type="button" class="min-h-[2.25rem] rounded-full border px-3 text-sm" :class="form.homework ? 'border-brand-700 bg-brand-700 text-white' : 'border-slate-300 text-slate-700 dark:text-slate-200'" :aria-pressed="form.homework" :disabled="disabled" @click="set('homework', !form.homework)">{{ form.homework ? '✓ ' : '' }}{{ t('log.homework') }}</button>
            </div>

            <p v-if="!logged && card.previous?.next_plan" class="text-xs text-slate-500">{{ t('log.you_planned') }} <b>{{ card.previous.next_plan }}</b></p>

            <div class="flex flex-wrap gap-x-4 gap-y-2 text-sm">
                <button v-if="!disabled && !logged && card.previous" type="button" class="font-medium text-brand-700 hover:underline dark:text-brand-300" @click="copyLast">{{ t('log.copy_last') }}</button>
                <button v-if="!disabled" type="button" class="font-medium text-brand-700 hover:underline dark:text-brand-300" :aria-expanded="notHeld" @click="notHeld = !notHeld">{{ t('log.class_not_held') }}</button>
                <button type="button" class="font-medium text-brand-700 hover:underline dark:text-brand-300" :aria-expanded="more" @click="more = !more">{{ t('log.more') }}</button>
                <button v-if="!disabled && form.id && siblings.length" type="button" class="font-medium text-brand-700 hover:underline dark:text-brand-300" @click="sameForAll">{{ t('log.same_all', { count: siblings.length }) }}</button>
                <Link v-if="form.id" :href="route('teaching.lessons', { log: form.id })" class="font-medium text-brand-700 hover:underline dark:text-brand-300">{{ t('log.lesson') }}</Link>
                <button v-if="!disabled && form.id" type="button" class="ml-auto text-red-700 hover:underline" @click="remove">{{ t('log.clear') }}</button>
            </div>

            <div v-if="notHeld" class="flex flex-wrap gap-2" role="group" :aria-label="t('log.why')">
                <button v-for="r in reasons" :key="r" type="button" class="min-h-[2.5rem] rounded-md border border-amber-400 bg-amber-50 px-3 text-sm font-medium text-amber-900" @click="missed(r)">{{ t(`reason.${r}`) }}</button>
            </div>

            <div v-if="more" class="space-y-2 border-t border-slate-200 pt-3 dark:border-slate-800">
                <label class="block text-xs font-semibold text-slate-500">{{ t('log.substitute') }}
                    <select v-model="form.substitute_id" class="field mt-1 font-normal" :disabled="disabled" @change="form.id ? push() : null"><option :value="null">—</option><option v-for="x in teachers.filter((y) => y.id !== card.teacher_id)" :key="x.id" :value="x.id">{{ x.name }}</option></select>
                </label>
                <label class="block text-xs font-semibold text-slate-500">{{ t('log.next_plan') }}<input v-model="form.next_plan" maxlength="255" class="field mt-1 font-normal" :disabled="disabled" @input="later" /></label>
                <label class="block text-xs font-semibold text-slate-500">{{ t('note') }}<input v-model="form.note" maxlength="500" class="field mt-1 font-normal" :disabled="disabled" @input="later" /></label>
                <p v-if="!form.id" class="text-xs text-slate-500">{{ t('log.saved_with') }}</p>
            </div>
        </div>
    </article>
</template>
