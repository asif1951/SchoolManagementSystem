<script setup>
import CalendarStrip from '@/Components/Calendar/CalendarStrip.vue'
import Shell from '@/Components/Teaching/Shell.vue'
import LogCard from '@/Components/Teaching/LogCard.vue'
import Dialog from '@/Components/Teaching/Dialog.vue'
import Chip from '@/Components/Teaching/Chip.vue'
import Tour from '@/Components/Teaching/Tour.vue'
import { Link, router } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import { useT } from '@/composables/useT'

const props = defineProps({
    date: String, today: String, teacherId: Number, seesAll: Boolean, teachers: Array, closed: Array,
    canWrite: Boolean, locked: Boolean, cards: Array, options: Object, extra: Array, todo: Object, unread: Array, flash: Object,
})
const t = useT('teaching')

const list = ref([])
const opts = ref({})
const version = ref(0)
watch(() => [props.cards, props.options], () => { list.value = props.cards.map((c) => ({ ...c })); opts.value = { ...props.options }; version.value++ }, { immediate: true })

const go = (date, teacher = props.teacherId) => router.get(route('teaching.log'), { date, teacher: props.seesAll ? teacher : undefined }, { preserveScroll: true })
const shift = (by) => { const d = new Date(`${props.date}T00:00:00Z`); d.setUTCDate(d.getUTCDate() + by); go(d.toISOString().slice(0, 10)) }
const heading = computed(() => new Date(`${props.date}T00:00:00Z`).toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' }))
const done = computed(() => list.value.filter((c) => c.log).length)

const apply = (card, data) => {
    card.log = data.log ?? null
    if (data.plan) { list.value.forEach((c) => { if (c.plan?.id === data.plan.id) c.plan = data.plan }); opts.value[data.plan.id] = data.options }
}
const saved = (card, data) => {
    apply(card, data)
    // a class not yet logged moves on to the next unfinished topic
    if (data.plan) list.value.filter((c) => !c.log && c.plan?.id === data.plan.id).forEach((c) => { const n = data.options.find((i) => i.progress < 100); c.suggest = { item_id: n?.id ?? null, topic_id: n?.topics.find((x) => x.best < 100)?.id ?? null } })
}
const removed = (card, data) => apply(card, { ...data, log: null })
const copied = () => router.reload({ preserveScroll: true, onSuccess: () => window.dispatchEvent(new CustomEvent('teaching:toast', { detail: { text: t('log.copied_all'), type: 'success' } })) })
const siblings = (card) => list.value.filter((c) => c.key !== card.key && !c.log && c.subject_id === card.subject_id && c.level === card.level && !c.covering)

const making = ref(false)
const makeMine = () => { making.value = true; router.post(route('teaching.plans.make-mine'), { session: props.todo.session }, { preserveScroll: true, onFinish: () => { making.value = false } }) }
const openNotice = (n) => router.post(route('teaching.notices.read'), { id: n.id }, { onSuccess: () => router.visit(n.url) })
const tour = ref(null)
const steps = computed(() => [{ key: 'welcome' }, { key: 'plan', url: route('teaching.plans') }, { key: 'log' }, { key: 'progress', url: route('teaching.progress') }])

const adding = ref(false)
const addExtra = (e) => {
    const items = opts.value[e.plan_id] || []
    const next = items.find((i) => i.progress < 100)
    const base = list.value.find((c) => c.plan?.id === e.plan_id)
    const [subject, ...cls] = e.label.split(' · ')
    list.value.push({ key: `extra-${Date.now()}`, class_id: e.class_id, subject_id: e.subject_id, period_id: null, teacher_id: props.teacherId, class: cls.join(' · '), level: base?.level, subject,
        period: null, time: null, covering: false, plan: base?.plan ?? { id: e.plan_id }, suggest: { item_id: next?.id ?? null, topic_id: next?.topics.find((x) => x.best < 100)?.id ?? null }, previous: base?.previous ?? null, log: null })
    adding.value = false
}
</script>

<template>
    <Shell :title="t('menu.today')" :subtitle="t('hint.today')" :wide="false">
        <template #actions>
            <button type="button" class="btn" @click="tour.show()">{{ t('tour.button') }}</button>
            <button v-if="canWrite && extra.length" type="button" class="btn" @click="adding = true">+ {{ t('log.extra') }}</button>
        </template>

        <section v-if="unread?.length" class="panel mb-3 border-l-4 !border-l-brand-700 p-3" :aria-label="t('dash.notices')">
            <h2 class="text-sm font-semibold">{{ t('dash.notices') }}</h2>
            <ul class="mt-1 space-y-1 text-sm"><li v-for="n in unread" :key="n.id"><button type="button" class="text-left hover:underline" @click="openNotice(n)">{{ n.text }} <span class="text-xs text-slate-400">{{ n.at }}</span></button></li></ul>
        </section>

        <section v-if="todo?.missing" class="panel mb-3 border-2 !border-brand-600 p-4">
            <h2 class="text-base font-semibold">{{ todo.plans ? t('now.more_plans', { count: todo.missing }) : t('now.start_title') }}</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">{{ t('now.start_help', { count: todo.missing }) }}</p>
            <button type="button" class="btn btn-primary mt-3 min-h-[2.75rem] w-full sm:w-auto sm:px-6" :disabled="making" @click="makeMine">{{ making ? t('working') : t('now.make_mine') }}</button>
        </section>

        <section v-if="todo?.pending.length" class="mb-3 rounded-md border border-amber-300 bg-amber-50 px-3 py-2.5 text-sm text-amber-900">
            <span class="font-semibold">{{ t('dash.pending') }}:</span>
            <button v-for="p in todo.pending" :key="p.date" type="button" class="ml-2 mt-1 rounded border border-amber-400 bg-white px-2 py-1 font-medium hover:bg-amber-100" @click="go(p.date)">{{ p.date }} ({{ p.missing }})</button>
        </section>

        <div class="panel mb-3 flex flex-wrap items-center justify-between gap-2 p-2">
            <button type="button" class="btn" :aria-label="t('log.prev_day')" @click="shift(-1)">←</button>
            <div class="flex-1 text-center">
                <div class="text-sm font-semibold">{{ heading }}</div>
                <input type="date" class="mt-0.5 rounded border-slate-200 py-0.5 text-xs dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100" :value="date" :max="today" :aria-label="t('date')" @change="go($event.target.value)" />
            </div>
            <button type="button" class="btn" :disabled="date >= today" :aria-label="t('log.next_day')" @click="shift(1)">→</button>
            <button v-if="date !== today" type="button" class="btn" @click="go(today)">{{ t('today') }}</button>
        </div>

        <select v-if="seesAll" class="field mb-3" :value="teacherId" :aria-label="t('teacher')" @change="go(date, Number($event.target.value))">
            <option v-if="!teachers.some((x) => x.id === teacherId)" :value="teacherId">{{ t('log.me') }}</option>
            <option v-for="x in teachers" :key="x.id" :value="x.id">{{ x.name }}</option>
        </select>

        <p v-if="closed.length" class="mb-3 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">{{ closed.join(', ') }}</p>
        <p v-if="locked" class="mb-3 rounded-md border border-slate-300 bg-slate-100 px-3 py-2 text-sm">{{ t('log.locked') }}</p>
        <p v-if="list.length" class="mb-2 text-sm text-slate-500" aria-live="polite">{{ t('log.progress', { done, total: list.length }) }}</p>

        <div v-if="!list.length" class="panel px-4 py-10 text-center text-sm text-slate-500">
            <p>{{ t('log.empty') }}</p>
            <button v-if="canWrite && extra.length" type="button" class="btn btn-primary mt-3" @click="adding = true">{{ t('log.add_extra') }}</button>
            <Link v-else :href="route('teaching.plans')" class="btn btn-primary mt-3">{{ t('log.make_plan') }}</Link>
        </div>

        <div class="space-y-3">
            <LogCard v-for="c in list" :key="`${version}-${c.key}`" :card="c" :date="date" :items="c.plan ? opts[c.plan.id] || [] : []" :teachers="teachers" :disabled="!canWrite" :siblings="siblings(c)" @saved="saved" @removed="removed" @copied="copied" />
        </div>

        <CalendarStrip class="mt-4" />

        <section v-if="todo?.behind.length" class="panel mt-4 p-4">
            <h2 class="text-sm font-semibold">{{ t('now.behind') }}</h2>
            <ul class="mt-2 space-y-2 text-sm"><li v-for="p in todo.behind" :key="p.id"><Link :href="route('teaching.plans.show', p.id)" class="flex items-center justify-between gap-2 hover:underline"><span class="min-w-0 truncate">{{ p.subject }} · {{ p.class }}</span><Chip :value="p.health" :gap="p.gap" /></Link></li></ul>
        </section>

        <Tour ref="tour" :steps="steps" />

        <Dialog v-if="adding" :title="t('log.add_extra')" @close="adding = false">
            <div class="space-y-2"><button v-for="e in extra" :key="e.plan_id" type="button" class="btn w-full justify-start" @click="addExtra(e)">{{ e.label }}</button></div>
        </Dialog>
    </Shell>
</template>
