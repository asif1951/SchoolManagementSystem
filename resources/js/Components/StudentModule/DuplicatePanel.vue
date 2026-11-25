<script setup>
import { Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import { useT } from '@/composables/useT'

/**
 * The warning shown when a save is blocked because the student looks like one already registered.
 *   kind "brn"    the same Birth Registration Number: there is no way past it, only the existing record to open.
 *   kind "likely" a similar name with the same birth date or guardian mobile: a user holding the override
 *                 permission may continue with a written reason.
 */
const props = defineProps({
    duplicate: { type: Object, default: null },          // the summary sent by the server
    message: { type: String, default: '' },
    canOverride: { type: Boolean, default: false },
    reason: { type: String, default: '' },
    processing: { type: Boolean, default: false },
})

const emit = defineEmits(['update:reason', 'override'])

const t = useT()

const isBrn = computed(() => props.duplicate?.kind === 'brn')
const overridable = computed(() => props.duplicate && !isBrn.value && props.canOverride && props.duplicate.overridable)
</script>

<template>
    <section v-if="message" class="mb-5 rounded-md border border-amber-300 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/30" role="alert">
        <h2 class="text-sm font-semibold text-amber-900 dark:text-amber-200">{{ t('duplicate_title') }}</h2>
        <p class="mt-1 text-sm text-amber-900 dark:text-amber-200">{{ isBrn ? t('duplicate_brn') : t('duplicate_likely') }}</p>

        <div v-if="duplicate?.student" class="mt-3 rounded border border-amber-200 bg-white p-3 text-sm dark:border-amber-900 dark:bg-slate-900">
            <p class="font-semibold text-slate-900 dark:text-white">{{ duplicate.student.name }}</p>
            <p class="mt-0.5 text-xs text-slate-600 dark:text-slate-400">
                {{ t('code') }}: <span class="font-mono">{{ duplicate.student.code }}</span>
                <template v-if="duplicate.student.dob"> · {{ t('dob') }}: {{ duplicate.student.dob }}</template>
                <template v-if="duplicate.student.class"> · {{ duplicate.student.class }} ({{ duplicate.student.session }})</template>
            </p>
            <Link :href="route('sm.students.show', duplicate.student.profile_id)" class="mt-2 inline-block text-xs font-semibold text-blue-700 hover:underline dark:text-blue-400">
                {{ t('open_existing') }} →
            </Link>
        </div>

        <!-- a likely duplicate: a user with the permission may add anyway, with a reason -->
        <div v-if="overridable" class="mt-4">
            <p class="text-xs text-amber-900 dark:text-amber-200">{{ t('override_hint') }}</p>
            <label class="mt-2 block text-xs font-medium text-slate-700 dark:text-slate-300" for="override_reason">{{ t('override_reason') }}</label>
            <textarea
                id="override_reason"
                :value="reason"
                rows="2"
                maxlength="500"
                class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-600/20 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100"
                @input="emit('update:reason', $event.target.value)"
            ></textarea>
            <button
                type="button"
                :disabled="processing || !reason.trim()"
                class="mt-2 inline-flex h-9 items-center rounded-md bg-amber-700 px-4 text-sm font-semibold text-white hover:bg-amber-800 disabled:cursor-not-allowed disabled:opacity-50"
                @click="emit('override')"
            >{{ t('add_anyway') }}</button>
        </div>

        <p v-else-if="!isBrn && duplicate" class="mt-3 text-xs font-medium text-amber-900 dark:text-amber-200">{{ t('no_override') }}</p>
    </section>
</template>
