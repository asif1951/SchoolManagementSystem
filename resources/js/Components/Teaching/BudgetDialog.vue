<script setup>
/** Asked before saving: too many periods stops the save; too few may be saved anyway. */
import Dialog from '@/Components/Teaching/Dialog.vue'
import { useT } from '@/composables/useT'

defineProps({ ask: { type: Object, required: true }, limit: { type: Number, required: true }, year: { type: String, required: true }, busy: Boolean })
defineEmits(['save', 'close'])
const t = useT('teaching')
</script>

<template>
    <Dialog :title="t(`budget.${ask.type}_title`)" @close="$emit('close')">
        <p v-if="ask.type === 'over'" class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-red-900" role="alert">{{ t('budget.over', { limit, year, planned: ask.planned, extra: ask.planned - limit }) }}</p>
        <p v-else>{{ t('budget.short', { limit, year, planned: ask.planned, left: limit - ask.planned }) }}</p>
        <div class="mt-4 flex justify-end gap-2">
            <button v-if="ask.type === 'over'" type="button" class="btn btn-primary" @click="$emit('close')">{{ t('budget.ok') }}</button>
            <template v-else>
                <button type="button" class="btn" @click="$emit('close')">{{ t('cancel') }}</button>
                <button type="button" class="btn btn-primary" :disabled="busy" @click="$emit('save')">{{ t('budget.save_anyway') }}</button>
            </template>
        </div>
    </Dialog>
</template>
