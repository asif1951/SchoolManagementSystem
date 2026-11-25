<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useT } from '@/composables/useT'

/**
 * Live progress of a background task. Asks the server every 1.5 s until the task is finished or has failed,
 * then reports the final state once. The page shows the result; this only shows how far the work is.
 */
const props = defineProps({
    taskId: { type: Number, required: true },
    initial: { type: Object, default: null },
})
const emit = defineEmits(['finished'])

const t = useT()
const task = ref(props.initial)
let timer = null

const isOver = (s) => s === 'done' || s === 'failed'

const poll = async () => {
    try {
        const res = await fetch(route('sm.tasks.show', props.taskId), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        if (res.ok) {
            task.value = await res.json()
        }
    } catch (e) {
        // a dropped request is retried on the next tick
    }

    if (task.value && isOver(task.value.status)) {
        clearInterval(timer)
        emit('finished', task.value)
    }
}

onMounted(() => {
    if (!task.value || !isOver(task.value.status)) {
        poll()
        timer = setInterval(poll, 1500)
    } else {
        emit('finished', task.value)
    }
})

onBeforeUnmount(() => clearInterval(timer))

watch(() => props.taskId, () => {
    task.value = null
    clearInterval(timer)
    poll()
    timer = setInterval(poll, 1500)
})
</script>

<template>
    <div v-if="task" role="status" aria-live="polite">
        <div class="flex items-center justify-between text-sm">
            <span class="font-medium text-slate-700 dark:text-slate-200">
                {{ task.status === 'queued' ? t('queued') : task.status === 'running' ? t('working') : task.status === 'done' ? t('finished') : t('failed') }}
            </span>
            <span class="tabular-nums text-slate-500 dark:text-slate-400">{{ task.processed }} / {{ task.total }} ({{ task.percent }}%)</span>
        </div>
        <div class="mt-1.5 h-2 overflow-hidden rounded bg-slate-200 dark:bg-slate-700" role="progressbar" :aria-valuenow="task.percent" aria-valuemin="0" aria-valuemax="100">
            <div class="h-full transition-all" :class="task.status === 'failed' ? 'bg-red-600' : 'bg-blue-700'" :style="{ width: task.percent + '%' }"></div>
        </div>
        <p v-if="task.error" class="mt-2 text-sm text-red-700 dark:text-red-400">{{ task.error }}</p>
    </div>
</template>
