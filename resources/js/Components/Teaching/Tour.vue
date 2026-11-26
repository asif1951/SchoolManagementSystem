<script setup>
/** A short first-time tour, remembered in this browser. */
import Dialog from '@/Components/Teaching/Dialog.vue'
import { Link } from '@inertiajs/vue3'
import { ref } from 'vue'
import { useT } from '@/composables/useT'

const props = defineProps({ steps: { type: Array, required: true }, name: { type: String, default: 'teaching.tour' } })
const t = useT('teaching')
const seen = () => { try { return localStorage.getItem(props.name) === '1' } catch { return true } }
const at = ref(seen() ? -1 : 0)
const close = () => { at.value = -1; try { localStorage.setItem(props.name, '1') } catch { /* private window */ } }
defineExpose({ show: () => { at.value = 0 } })
</script>

<template>
    <Dialog v-if="at >= 0" :title="t(`tour.${steps[at].key}_title`)" @close="close">
        <p class="text-base">{{ t(`tour.${steps[at].key}`) }}</p>
        <Link v-if="steps[at].url" :href="steps[at].url" class="mt-2 inline-block font-semibold text-brand-700 underline" @click="close">{{ t('open') }}</Link>
        <div class="mt-4 flex items-center justify-between gap-2">
            <span class="text-xs text-slate-500">{{ at + 1 }} / {{ steps.length }}</span>
            <div class="flex gap-2"><button type="button" class="btn" @click="close">{{ t('tour.skip') }}</button><button type="button" class="btn btn-primary" @click="at < steps.length - 1 ? at++ : close()">{{ at < steps.length - 1 ? t('next') : t('tour.finish') }}</button></div>
        </div>
    </Dialog>
</template>
