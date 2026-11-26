<script setup>
import Shell from '@/Components/Teaching/Shell.vue'
import Bar from '@/Components/Teaching/Bar.vue'
import Chip from '@/Components/Teaching/Chip.vue'
import Dialog from '@/Components/Teaching/Dialog.vue'
import Timeline from '@/Components/Teaching/Timeline.vue'
import { Link, router } from '@inertiajs/vue3'
import { computed, reactive, ref } from 'vue'
import { useT } from '@/composables/useT'

const props = defineProps({
    plan: Object, items: Array, warnings: Array, session: Object, perWeek: Number, reviews: Array,
    copySources: Array, copyTargets: Array, can: Object, approval: Boolean, flash: Object,
})
const t = useT('teaching')
const toast = (text, type = 'success', undo = null) => window.dispatchEvent(new CustomEvent('teaching:toast', { detail: { text, type, undo } }))
const keep = { preserveScroll: true, preserveState: true }

const readView = () => { try { return localStorage.getItem('teaching.plan.view') } catch { return null } }
const view = ref(readView() || 'table')
const setView = (v) => { view.value = v; try { localStorage.setItem('teaching.plan.view', v) } catch { /* private window */ } }

const update = (item, data, undo = null) => router.put(route('teaching.items.update', item.id), data, { ...keep, onSuccess: () => { if (undo) toast(t('msg.dates_saved'), 'success', undo) } })
const moveDates = (item, starts_on, ends_on) => {
    const old = { starts_on: item.starts_on, ends_on: item.ends_on }
    update(item, { starts_on, ends_on }, () => update(item, old))
}
const setDate = (item, field, value) => { if ((value || null) !== item[field]) update(item, { [field]: value || null }) }

const dialog = ref(null)
const schedule = reactive({ from: props.session.today > props.session.from && props.session.today < props.session.to && props.items.some((i) => i.progress > 0) ? props.session.today : props.session.from, to: props.session.to })
const busy = ref(false)
const menu = ref(null)
const undated = computed(() => props.items.some((i) => !i.starts_on))
const post = (name, data = {}, done = () => {}) => { busy.value = true; router.post(route(name, props.plan.id), data, { preserveScroll: true, onSuccess: () => { dialog.value = null; done() }, onFinish: () => { busy.value = false } }) }

const copy = reactive({ from: '', to: '' })
const decision = reactive({ comment: '' })
const review = ref('')
const sendReview = () => post('teaching.plans.review', { body: review.value }, () => { review.value = '' })
const remove = () => router.delete(route('teaching.plans.destroy', props.plan.id))
const removeItem = (i) => { dialog.value = { item: i } }
const doRemoveItem = () => router.delete(route('teaching.items.destroy', dialog.value.item.id), { ...keep, onSuccess: () => { dialog.value = null } })
</script>

<template>
    <Shell :title="`${plan.subject} · ${plan.class}`" :subtitle="`${plan.teacher} · ${plan.year} · ${perWeek} ${t('plan.per_week')}`">
        <template #actions>
            <Link :href="route('teaching.plans')" class="btn">← {{ t('back') }}</Link>
            <button v-if="can.submit" type="button" class="btn btn-primary" :disabled="busy" @click="post('teaching.plans.submit')">{{ t('plan.submit') }}</button>
            <button v-if="can.edit && !undated" type="button" class="btn" :disabled="!items.length" @click="dialog = 'schedule'">{{ t('plan.auto') }}</button>
            <details ref="menu" class="relative">
                <summary class="btn cursor-pointer list-none">{{ t('plan.more') }} ▾</summary>
                <div class="absolute right-0 z-20 mt-1 w-64 overflow-hidden rounded-md border border-slate-200 bg-white py-1 text-sm shadow-lg dark:border-slate-700 dark:bg-slate-900" @click="menu.open = false">
                    <a :href="route('teaching.period.print', { session: plan.year, plan: plan.id, mode: 'year' })" target="_blank" rel="noopener" class="block px-4 py-2 hover:bg-brand-50 dark:hover:bg-slate-800">{{ t('print') }}</a>
                    <template v-if="can.edit">
                        <button type="button" class="block w-full px-4 py-2 text-left hover:bg-brand-50 dark:hover:bg-slate-800" @click="dialog = 'copy'">{{ t('plan.copy') }}</button>
                        <button type="button" class="block w-full px-4 py-2 text-left hover:bg-brand-50 dark:hover:bg-slate-800" @click="post('teaching.plans.sync')">{{ t('plan.sync') }}</button>
                        <button type="button" class="block w-full border-t border-slate-100 px-4 py-2 text-left text-red-700 hover:bg-red-50 dark:border-slate-800" @click="dialog = 'delete'">{{ t('plan.delete') }}</button>
                    </template>
                </div>
            </details>
        </template>

        <section class="panel mb-4 p-4">
            <div class="flex flex-wrap items-center gap-3"><Chip :value="plan.health" :gap="plan.gap" /><Chip v-if="approval" :value="plan.status" />
                <span v-if="plan.finish" class="text-sm text-slate-500">{{ t('finish_by') }} <b>{{ plan.finish }}</b></span>
                <span v-if="plan.delayed" class="text-sm font-semibold text-red-700">{{ plan.delayed }} {{ t('status.delayed') }}</span></div>
            <div class="mt-3"><Bar :done="plan.done" :expected="plan.health === 'none' ? null : plan.expected" :health="plan.health" /></div>
        </section>

        <section v-if="can.edit && undated && items.length" class="panel mb-4 border-2 !border-brand-600 p-4">
            <h2 class="text-base font-semibold">{{ t('plan.next_title') }}</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">{{ t('plan.next_help') }}</p>
            <button type="button" class="btn btn-primary mt-3 min-h-[2.75rem] px-6" @click="dialog = 'schedule'">{{ t('plan.auto') }}</button>
        </section>

        <section v-if="can.approve" class="panel mb-4 border-l-4 !border-l-amber-500 p-4">
            <p class="text-sm font-semibold">{{ t('plan.waiting') }}</p>
            <textarea v-model="decision.comment" rows="2" maxlength="1000" class="field mt-2" :placeholder="t('plan.comment_optional')" :aria-label="t('note')"></textarea>
            <div class="mt-2 flex gap-2"><button type="button" class="btn btn-primary" :disabled="busy" @click="post('teaching.plans.decide', { approve: true, comment: decision.comment })">{{ t('plan.approve') }}</button>
                <button type="button" class="btn" :disabled="busy || !decision.comment.trim()" @click="post('teaching.plans.decide', { approve: false, comment: decision.comment })">{{ t('plan.return') }}</button></div>
        </section>

        <ul v-if="warnings.length" class="mb-4 space-y-1 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="status">
            <li v-for="(w, i) in warnings" :key="i">⚠ {{ w.text }}</li>
        </ul>

        <div v-if="!items.length" class="panel px-4 py-10 text-center text-sm text-slate-500">
            <p>{{ t('plan.no_chapters') }}</p>
            <button v-if="can.edit" type="button" class="btn btn-primary mt-3" @click="post('teaching.plans.sync')">{{ t('plan.sync') }}</button>
        </div>

        <section v-else class="panel overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-2 dark:border-slate-800">
                <div class="flex gap-1" role="tablist">
                    <button v-for="v in ['table', 'timeline']" :key="v" type="button" role="tab" :aria-selected="view === v" class="rounded px-3 py-1.5 text-sm font-medium" :class="view === v ? 'bg-brand-700 text-white' : 'text-slate-600 hover:bg-slate-100'" @click="setView(v)">{{ t(`plan.${v}`) }}</button>
                </div>
            </div>

            <div v-if="view === 'timeline'" class="p-3">
                <p v-if="can.edit" class="mb-2 text-xs text-slate-500">{{ t('plan.timeline_help') }}</p>
                <Timeline :items="items" :session="session" :disabled="!can.edit" @change="moveDates" />
            </div>

            <div v-else class="overflow-x-auto">
                <table class="data-table min-w-[720px] [&_td]:!px-2 [&_th]:!px-2">
                    <thead><tr><th>#</th><th>{{ t('chapter') }}</th><th>{{ t('from') }}</th><th>{{ t('to') }}</th><th>{{ t('periods') }}</th><th class="w-20">{{ t('done') }}</th><th>{{ t('status_label') }}</th><th v-if="can.edit" class="num">{{ t('actions') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="(i, k) in items" :key="i.id">
                            <td class="muted">{{ k + 1 }}</td>
                            <td><div class="font-medium text-slate-900 dark:text-white">{{ i.chapter }}</div><div v-if="i.topics.length" class="max-w-[11rem] truncate text-xs text-slate-500" :title="i.topics.join(', ')">{{ i.topics.join(', ') }}</div></td>
                            <td><input type="date" class="field w-36 !px-2" :value="i.starts_on" :min="session.from" :max="session.to" :disabled="!can.edit" :aria-label="`${i.chapter} ${t('from')}`" @change="setDate(i, 'starts_on', $event.target.value)" /></td>
                            <td><input type="date" class="field w-36 !px-2" :value="i.ends_on" :min="session.from" :max="session.to" :disabled="!can.edit" :aria-label="`${i.chapter} ${t('to')}`" @change="setDate(i, 'ends_on', $event.target.value)" /></td>
                            <td><input type="number" min="1" max="500" class="field w-16 !px-2" :value="i.planned_periods" :disabled="!can.edit" :aria-label="`${i.chapter} ${t('periods')}`" @change="update(i, { planned_periods: Number($event.target.value) || 1 })" /></td>
                            <td><Bar :done="i.progress" compact /><span class="text-xs tabular-nums text-slate-500">{{ Math.round(i.progress) }}%</span></td>
                            <td><Chip :value="i.status" /></td>
                            <td v-if="can.edit" class="num">
                                <button type="button" class="block w-full whitespace-nowrap text-right text-xs font-medium text-brand-700 hover:underline" @click="update(i, { completed: !i.completed })">{{ i.completed ? t('plan.reopen') : t('plan.complete') }}</button>
                                <button type="button" class="mt-1 block w-full text-right text-xs text-red-700 hover:underline" @click="removeItem(i)">{{ t('delete') }}</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel mt-4 p-4">
            <h2 class="text-sm font-semibold">{{ t('plan.reviews') }}</h2>
            <form v-if="can.review" class="mt-2 flex flex-wrap gap-2" @submit.prevent="sendReview">
                <input v-model="review" maxlength="1000" class="field min-w-0 flex-1" :placeholder="t('plan.write_review')" :aria-label="t('plan.write_review')" />
                <button class="btn btn-primary" :disabled="busy || !review.trim()">{{ t('plan.send') }}</button>
            </form>
            <ul class="mt-3 space-y-2 text-sm">
                <li v-for="r in reviews" :key="r.id" class="rounded-md border border-slate-200 px-3 py-2 dark:border-slate-800"><span class="font-semibold">{{ r.by }}</span> <Chip v-if="r.kind !== 'comment'" :value="r.kind" /> <span class="text-xs text-slate-400">{{ r.at }}</span><p class="mt-0.5">{{ r.body }}</p></li>
                <li v-if="!reviews.length" class="text-slate-500">{{ t('plan.no_reviews') }}</li>
            </ul>
        </section>

        <Dialog v-if="dialog === 'schedule'" :title="t('plan.auto')" @close="dialog = null">
            <p class="mb-3">{{ t('plan.auto_help', { week: perWeek }) }}</p>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="field-label" for="sc-from">{{ t('from') }}</label><input id="sc-from" v-model="schedule.from" type="date" class="field" :min="session.from" :max="session.to" /></div>
                <div><label class="field-label" for="sc-to">{{ t('to') }}</label><input id="sc-to" v-model="schedule.to" type="date" class="field" :min="session.from" :max="session.to" /></div>
            </div>
            <p class="mt-2 text-xs text-slate-500">{{ t('plan.auto_warn') }}</p>
            <div class="mt-4 flex justify-end gap-2"><button type="button" class="btn" @click="dialog = null">{{ t('cancel') }}</button><button type="button" class="btn btn-primary" :disabled="busy" @click="post('teaching.plans.schedule', schedule)">{{ busy ? t('working') : t('plan.auto') }}</button></div>
        </Dialog>

        <Dialog v-if="dialog === 'copy'" :title="t('plan.copy')" @close="dialog = null">
            <label class="field-label" for="cp-from">{{ t('plan.copy_from') }}</label>
            <div class="flex gap-2"><select id="cp-from" v-model="copy.from" class="field"><option value="">—</option><option v-for="s in copySources" :key="s.id" :value="s.id">{{ s.label }}</option></select>
                <button type="button" class="btn btn-primary" :disabled="!copy.from || busy" @click="post('teaching.plans.copy', { direction: 'from', other: copy.from })">{{ t('plan.copy_here') }}</button></div>
            <label class="field-label mt-4" for="cp-to">{{ t('plan.copy_to') }}</label>
            <div class="flex gap-2"><select id="cp-to" v-model="copy.to" class="field"><option value="">—</option><option v-for="s in copyTargets" :key="s.id" :value="s.id">{{ s.label }}</option></select>
                <button type="button" class="btn btn-primary" :disabled="!copy.to || busy" @click="post('teaching.plans.copy', { direction: 'to', other: copy.to })">{{ t('plan.copy_there') }}</button></div>
        </Dialog>

        <Dialog v-if="dialog === 'delete'" :title="t('plan.delete')" @close="dialog = null">
            <p>{{ t('plan.delete_confirm') }}</p>
            <div class="mt-4 flex justify-end gap-2"><button type="button" class="btn" @click="dialog = null">{{ t('cancel') }}</button><button type="button" class="btn btn-danger" @click="remove">{{ t('delete') }}</button></div>
        </Dialog>

        <Dialog v-if="dialog?.item" :title="t('delete')" @close="dialog = null">
            <p>{{ t('plan.remove_chapter', { chapter: dialog.item.chapter }) }}</p>
            <div class="mt-4 flex justify-end gap-2"><button type="button" class="btn" @click="dialog = null">{{ t('cancel') }}</button><button type="button" class="btn btn-danger" @click="doRemoveItem">{{ t('delete') }}</button></div>
        </Dialog>
    </Shell>
</template>
