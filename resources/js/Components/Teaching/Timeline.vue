<script setup>
/**
 * One bar per chapter across the session. Drag a bar to move it, drag either end to change the date; with the keyboard,
 * arrow keys move it a day (Shift: a week). Emits "change" (item, starts_on, ends_on) once, when released.
 */
import { computed, onBeforeUnmount, reactive, ref } from 'vue'
import { useT } from '@/composables/useT'

const props = defineProps({ items: { type: Array, required: true }, session: { type: Object, required: true }, disabled: { type: Boolean, default: false } })
const emit = defineEmits(['change'])
const t = useT('teaching')

const DAY = 86400000
const num = (d) => Math.round(Date.parse(`${d}T00:00:00Z`) / DAY)
const str = (n) => new Date(n * DAY).toISOString().slice(0, 10)
const first = computed(() => num(props.session.from))
const total = computed(() => num(props.session.to) - first.value + 1)
const pct = (d) => ((num(d) - first.value) / total.value) * 100
const span = (a, b) => ((num(b) - num(a) + 1) / total.value) * 100

const months = computed(() => {
    const out = []
    const [y] = props.session.from.split('-').map(Number)
    for (let m = 0; m < 12; m++) {
        const d = new Date(Date.UTC(y, m, 1)).toISOString().slice(0, 10)
        out.push({ left: pct(d), label: new Date(Date.UTC(y, m, 1)).toLocaleString('en', { month: 'short', timeZone: 'UTC' }) })
    }
    return out
})

const live = reactive({})        // id -> [start, end] while being dragged
const dates = (i) => live[i.id] || [i.starts_on, i.ends_on]
const track = ref(null)
let drag = null

const clamp = (a, b, mode) => {
    const lo = first.value
    const hi = first.value + total.value - 1
    if (mode === 'move') { const len = b - a; a = Math.max(lo, Math.min(a, hi - len)); return [a, a + len] }
    return mode === 'start' ? [Math.max(lo, Math.min(a, b)), b] : [a, Math.min(hi, Math.max(b, a))]
}
const onMove = (e) => {
    if (!drag) return
    const by = Math.round(((e.clientX - drag.x) / drag.width) * total.value)
    const [a, b] = [drag.a + (drag.mode !== 'end' ? by : 0), drag.b + (drag.mode !== 'start' ? by : 0)]
    live[drag.item.id] = clamp(a, b, drag.mode).map(str)
}
const release = (save = true) => {
    window.removeEventListener('pointermove', onMove)
    window.removeEventListener('pointerup', onUp)
    window.removeEventListener('pointercancel', onCancel)
    document.body.style.userSelect = ''
    const d = drag
    drag = null
    if (!d) return
    const v = live[d.item.id]
    if (save && v && (v[0] !== d.item.starts_on || v[1] !== d.item.ends_on)) emit('change', d.item, v[0], v[1])
    delete live[d.item.id]
}
const onUp = () => release(true)
const onCancel = () => release(false)
const start = (e, item, mode) => {
    if (props.disabled || e.button !== 0) return
    e.preventDefault()
    e.stopPropagation()
    drag = { item, mode, x: e.clientX, width: track.value.getBoundingClientRect().width, a: num(item.starts_on), b: num(item.ends_on) }
    document.body.style.userSelect = 'none'
    window.addEventListener('pointermove', onMove)
    window.addEventListener('pointerup', onUp)
    window.addEventListener('pointercancel', onCancel)
}
const key = (e, item) => {
    if (props.disabled || !['ArrowLeft', 'ArrowRight'].includes(e.key)) return
    e.preventDefault()
    const by = (e.key === 'ArrowLeft' ? -1 : 1) * (e.shiftKey ? 7 : 1)
    const [a, b] = clamp(num(item.starts_on) + by, num(item.ends_on) + by, 'move').map(str)
    emit('change', item, a, b)
}
onBeforeUnmount(() => release(false))

const color = { completed: 'bg-green-600', running: 'bg-brand-700', upcoming: 'bg-slate-500', delayed: 'bg-red-600' }
</script>

<template>
    <div class="overflow-x-auto">
        <div class="min-w-[760px]">
            <div class="flex text-xs text-slate-500">
                <div class="w-48 shrink-0"></div>
                <div class="relative h-5 flex-1"><span v-for="m in months" :key="m.label" class="absolute border-l border-slate-300 pl-1" :style="{ left: m.left + '%' }">{{ m.label }}</span></div>
            </div>
            <div v-for="(i, k) in items" :key="i.id" class="flex items-center border-t border-slate-100 py-1 dark:border-slate-800">
                <div class="w-48 shrink-0 truncate pr-2 text-sm" :title="i.chapter">{{ k + 1 }}. {{ i.chapter }}</div>
                <div :ref="(el) => { if (k === 0) track = el }" class="relative h-8 flex-1">
                    <span v-for="c in session.closed" :key="c[0] + c[2]" class="absolute inset-y-0 bg-slate-100 dark:bg-slate-800" :style="{ left: pct(c[0]) + '%', width: span(c[0], c[1]) + '%' }" :title="c[2]"></span>
                    <span class="absolute inset-y-0 w-px bg-red-500" :style="{ left: pct(session.today < session.from ? session.from : session.today > session.to ? session.to : session.today) + '%' }" :title="t('today')"></span>
                    <div v-if="dates(i)[0]" class="absolute top-1 flex h-6 select-none items-center rounded text-[11px] font-medium text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-slate-900"
                         :class="[color[i.status], disabled ? '' : 'cursor-grab touch-none']" :style="{ left: pct(dates(i)[0]) + '%', width: `max(10px, ${span(dates(i)[0], dates(i)[1])}%)` }"
                         :tabindex="disabled ? -1 : 0" role="slider" :aria-label="`${i.chapter}: ${dates(i)[0]} – ${dates(i)[1]}`" :aria-valuetext="`${dates(i)[0]} – ${dates(i)[1]}`" :title="`${dates(i)[0]} – ${dates(i)[1]}`"
                         @pointerdown="start($event, i, 'move')" @keydown="key($event, i)">
                        <span v-if="!disabled" class="h-full w-2.5 shrink-0 cursor-ew-resize rounded-l bg-black/25" aria-hidden="true" @pointerdown="start($event, i, 'start')"></span>
                        <span class="min-w-0 flex-1 truncate px-1">{{ live[i.id] ? `${live[i.id][0].slice(5)} → ${live[i.id][1].slice(5)}` : `${Math.round(i.progress)}%` }}</span>
                        <span v-if="!disabled" class="h-full w-2.5 shrink-0 cursor-ew-resize rounded-r bg-black/25" aria-hidden="true" @pointerdown="start($event, i, 'end')"></span>
                    </div>
                    <span v-else class="absolute left-1 top-1.5 text-xs text-slate-400">{{ t('plan.no_dates') }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
