<script setup>
/**
 * A list whose order can be changed by dragging the handle (a solid copy follows the pointer) or with the arrow buttons.
 * Emits "reorder" with the ids in their new order.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useT } from '@/composables/useT'

const props = defineProps({ items: { type: Array, required: true }, disabled: { type: Boolean, default: false } })
const emit = defineEmits(['reorder'])
const t = useT('teaching')

const list = ref([...props.items])
watch(() => props.items, (v) => { list.value = [...v] }, { deep: true })

const dragging = ref(null)
const ghost = ref(null)
const root = ref(null)
const ghostText = computed(() => list.value.find((x) => x.id === dragging.value)?.name ?? '')
let offset = { x: 0, y: 0 }
let startOrder = ''

const ids = () => list.value.map((x) => x.id)
const commit = () => { if (ids().join() !== startOrder) emit('reorder', ids()) }

const move = (i, by) => {
    const j = i + by
    if (j < 0 || j >= list.value.length) return
    startOrder = ids().join()
    const [x] = list.value.splice(i, 1)
    list.value.splice(j, 0, x)
    commit()
}

const place = (e) => { if (ghost.value) ghost.value.style.transform = `translate(${Math.round(e.clientX - offset.x)}px, ${Math.round(e.clientY - offset.y)}px)` }
const onMove = (e) => {
    e.preventDefault()
    place(e)
    const over = [...root.value.children].find((el) => { const r = el.getBoundingClientRect(); return e.clientY >= r.top && e.clientY <= r.bottom })
    if (over) {
        const to = [...root.value.children].indexOf(over)
        const from = list.value.findIndex((x) => x.id === dragging.value)
        if (to !== from && from >= 0) { const [x] = list.value.splice(from, 1); list.value.splice(to, 0, x) }
    }
    if (e.clientY > window.innerHeight - 40) window.scrollBy(0, 12); else if (e.clientY < 60) window.scrollBy(0, -12)
}
const stop = () => {
    if (dragging.value === null) return
    dragging.value = null
    window.removeEventListener('pointermove', onMove)
    window.removeEventListener('pointerup', stop)
    window.removeEventListener('pointercancel', stop)
    document.body.style.userSelect = ''
    commit()
}
const start = (e, item) => {
    if (props.disabled || e.button !== 0) return
    e.preventDefault()
    startOrder = ids().join()
    dragging.value = item.id
    offset = { x: 20, y: 18 }
    document.body.style.userSelect = 'none'
    window.addEventListener('pointermove', onMove)
    window.addEventListener('pointerup', stop)
    window.addEventListener('pointercancel', stop)
    requestAnimationFrame(() => place(e))
}
onBeforeUnmount(stop)
</script>

<template>
    <div>
        <ul ref="root" class="space-y-2">
            <li v-for="(item, i) in list" :key="item.id" class="rounded-md border-2 bg-white dark:bg-slate-900" :class="dragging === item.id ? 'border-dashed border-brand-600 bg-brand-50' : 'border-slate-200 dark:border-slate-800'">
                <div class="flex items-start gap-2 p-2">
                    <div v-if="!disabled" class="flex flex-col items-center">
                        <button type="button" class="cursor-grab touch-none rounded px-1.5 text-lg leading-6 text-slate-400 hover:bg-slate-100 hover:text-slate-700" :aria-label="`${t('drag')} ${item.name}`" @pointerdown="start($event, item)">⠿</button>
                        <div class="flex text-xs text-slate-400">
                            <button type="button" class="px-0.5 hover:text-slate-800 disabled:opacity-30" :disabled="i === 0" :aria-label="`${t('move_up')} ${item.name}`" @click="move(i, -1)">↑</button>
                            <button type="button" class="px-0.5 hover:text-slate-800 disabled:opacity-30" :disabled="i === list.length - 1" :aria-label="`${t('move_down')} ${item.name}`" @click="move(i, 1)">↓</button>
                        </div>
                    </div>
                    <div class="min-w-0 flex-1"><slot :item="item" :index="i" /></div>
                </div>
            </li>
        </ul>
        <div v-show="dragging !== null" ref="ghost" class="pointer-events-none fixed left-0 top-0 z-[80] max-w-xs truncate rounded-md border-2 border-brand-900 bg-brand-700 px-3 py-2 text-sm font-semibold text-white shadow-lg" aria-hidden="true">{{ ghostText }}</div>
    </div>
</template>
