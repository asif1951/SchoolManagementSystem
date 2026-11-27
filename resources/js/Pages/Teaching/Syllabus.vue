<script setup>
import Shell from '@/Components/Teaching/Shell.vue'
import Dialog from '@/Components/Teaching/Dialog.vue'
import SortList from '@/Components/Teaching/SortList.vue'
import BudgetBar from '@/Components/Teaching/BudgetBar.vue'
import BudgetDialog from '@/Components/Teaching/BudgetDialog.vue'
import TaskProgress from '@/Components/StudentModule/TaskProgress.vue'
import { router } from '@inertiajs/vue3'
import { computed, reactive, ref, watch } from 'vue'
import { useT } from '@/composables/useT'

const props = defineProps({
    years: Array, session: String, classNames: Array, className: String, subjects: Array, subjectId: Number,
    chapters: Array, earlier: String, canEdit: Boolean, flash: Object, budget: { type: Object, default: null },
})
const t = useT('teaching')
const toast = (text, type = 'success', undo = null) => window.dispatchEvent(new CustomEvent('teaching:toast', { detail: { text, type, undo } }))

const pick = reactive({ session: props.session, class: props.className, subject: props.subjectId })
const load = (changed) => {
    if (changed === 'class') pick.subject = null
    router.get(route('teaching.syllabus'), { session: pick.session, class: pick.class || undefined, subject: pick.subject || undefined }, { preserveState: false, preserveScroll: true })
}
const set = computed(() => ({ academic_year: props.session, class_name: props.className, subject_id: props.subjectId }))
const keep = { preserveScroll: true, preserveState: true }

const lines = ref('')
const rename = (c, field, value) => {
    const v = value.trim()
    if (v === c[field] || v === '') return
    router.put(route('teaching.syllabus.update', c.id), { [field]: v }, keep)
}

// ---- periods: typed freely, totalled live, saved together -------------------------------
const draft = reactive({})
const resetDraft = () => { Object.keys(draft).forEach((k) => delete draft[k]); for (const c of props.chapters) draft[c.id] = c.periods || '' }
watch(() => props.chapters.map((c) => `${c.id}:${c.periods}`).join(), resetDraft, { immediate: true })
const saved = computed(() => props.chapters.reduce((n, c) => n + (c.periods || 0), 0))
const planned = computed(() => props.chapters.reduce((n, c) => n + (Number(draft[c.id]) || 0), 0))
const dirty = computed(() => props.chapters.some((c) => (Number(draft[c.id]) || 0) !== (c.periods || 0)))
const limit = computed(() => props.budget?.limit ?? null)
const note = computed(() => (limit.value === null ? '' : [t('budget.from_routine'), props.budget.section ? t('budget.section', { name: props.budget.section }) : ''].join(' ').trim()))
const busy = ref(false)
const ask = ref(null)                              // { type: 'over' | 'short', planned, run }
/** over the year's periods: stopped. Under: asked. Exactly right, or no figure: saved at once. */
const check = (total, run) => {
    if (limit.value !== null && total !== limit.value) ask.value = { type: total > limit.value ? 'over' : 'short', planned: total, run }
    else run()
}
const finish = { ...keep, onStart: () => { busy.value = true }, onFinish: () => { busy.value = false; ask.value = null } }
const savePeriods = () => check(planned.value, () => router.put(route('teaching.syllabus.periods'), { ...set.value, periods: Object.fromEntries(props.chapters.map((c) => [c.id, Number(draft[c.id]) || 0])) }, finish))
// "Name | 8" on a line that is not a topic gives that chapter 8 periods
const typed = computed(() => lines.value.split('\n').reduce((n, l) => { const m = /\|\s*(\d{1,3})\s*$/.exec(l.trim()); return n + (m && !/^[-•*]/.test(l.trim()) ? Number(m[1]) : 0) }, 0))
const send = () => router.post(route('teaching.syllabus.store'), { ...set.value, text: lines.value }, { ...finish, onSuccess: () => { lines.value = '' } })
const add = () => (typed.value ? check(saved.value + typed.value, send) : send())
const removeChapter = (c) => router.delete(route('teaching.syllabus.destroy', c.id), { ...keep, onSuccess: () => toast(t('msg.chapter_deleted'), 'success', () => router.post(route('teaching.syllabus.restore', c.id), {}, keep)) })
const reorder = (ids) => router.post(route('teaching.syllabus.reorder'), { ...set.value, ids }, keep)

const topicText = reactive({})
const addTopic = (c) => { const name = (topicText[c.id] || '').trim(); if (name) router.post(route('teaching.syllabus.topics.store', c.id), { name }, { ...keep, onSuccess: () => { topicText[c.id] = '' } }) }
const renameTopic = (x, value) => { const v = value.trim(); if (v && v !== x.name) router.put(route('teaching.syllabus.topics.update', x.id), { name: v }, keep) }
const removeTopic = (x) => router.delete(route('teaching.syllabus.topics.destroy', x.id), { ...keep, onSuccess: () => toast(t('msg.topic_deleted'), 'success', () => router.post(route('teaching.syllabus.topics.restore', x.id), {}, keep)) })
const reorderTopics = (c, ids) => router.post(route('teaching.syllabus.topics.reorder', c.id), { ids }, keep)

const open = reactive({})
const dialog = ref(null)

// copy
const copy = reactive({ to_year: props.session, to_classes: [] })
const doCopy = () => router.post(route('teaching.syllabus.copy'), { ...set.value, ...copy }, { ...keep, onSuccess: () => { dialog.value = null } })
const copyEarlier = () => router.post(route('teaching.syllabus.copy'), { academic_year: props.earlier, class_name: props.className, subject_id: props.subjectId, to_year: props.session, to_classes: [props.className] }, { preserveScroll: true })
const nextYears = computed(() => [...new Set([String(Number(props.session.slice(0, 4)) + 1), ...props.years])].sort().reverse())

// import
const imp = reactive({ file: null, busy: false, preview: null, task: null, error: '' })
const upload = async () => {
    if (!imp.file) return
    imp.busy = true; imp.error = ''; imp.preview = null
    const data = new FormData()
    data.append('file', imp.file)
    try { imp.preview = (await window.axios.post(route('teaching.syllabus.preview'), data)).data }
    catch (e) { imp.error = e.response?.data?.errors ? Object.values(e.response.data.errors)[0][0] : (e.response?.data?.message || t('msg.failed')) }
    imp.busy = false
}
const confirmImport = async () => {
    imp.busy = true
    try { imp.task = (await window.axios.post(route('teaching.syllabus.confirm'), { token: imp.preview.token, academic_year: props.session })).data.task }
    catch (e) { imp.error = e.response?.data?.message || t('msg.failed') }
    imp.busy = false
}
const imported = (task) => { if (task.status === 'done') router.reload({ preserveScroll: true }) }
const openImport = () => { Object.assign(imp, { file: null, busy: false, preview: null, task: null, error: '' }); dialog.value = 'import' }
</script>

<template>
    <Shell :title="t('nav.syllabus')" :subtitle="t('syllabus.subtitle')">
        <template #actions>
            <template v-if="canEdit">
                <button type="button" class="btn" @click="openImport">{{ t('syllabus.import') }}</button>
                <button type="button" class="btn" :disabled="!chapters.length" @click="copy.to_classes = []; dialog = 'copy'">{{ t('syllabus.copy') }}</button>
            </template>
        </template>

        <section class="panel mb-4 grid grid-cols-1 gap-3 p-4 sm:grid-cols-3">
            <div><label class="field-label" for="s-year">{{ t('session') }}</label><select id="s-year" v-model="pick.session" class="field" @change="load('session')"><option v-for="y in years" :key="y" :value="y">{{ y }}</option></select></div>
            <div><label class="field-label" for="s-class">{{ t('class') }}</label><select id="s-class" v-model="pick.class" class="field" @change="load('class')"><option v-for="c in classNames" :key="c" :value="c">{{ c }}</option></select></div>
            <div><label class="field-label" for="s-subject">{{ t('subject') }}</label><select id="s-subject" v-model="pick.subject" class="field" @change="load('subject')"><option v-for="s in subjects" :key="s.id" :value="s.id">{{ s.name }} ({{ s.chapters }})</option></select></div>
        </section>

        <p v-if="!subjectId" class="panel px-4 py-10 text-center text-sm text-slate-500">{{ t('syllabus.no_class') }}</p>

        <template v-else>
            <BudgetBar v-if="budget" :limit="limit" :planned="planned" :year="session" :note="note" :dirty="dirty" :busy="busy" :can-save="canEdit && chapters.length > 0" @save="savePeriods" @reset="resetDraft" />

            <div v-if="!chapters.length" class="panel mb-4 px-4 py-8 text-center">
                <p class="text-sm text-slate-600 dark:text-slate-300">{{ t('syllabus.empty') }}</p>
                <button v-if="canEdit && earlier" type="button" class="btn btn-primary mt-3" @click="copyEarlier">{{ t('syllabus.copy_from_year', { year: earlier }) }}</button>
                <p v-else-if="earlier" class="mt-2 text-sm text-slate-500">{{ t('syllabus.uses_year', { year: earlier }) }}</p>
            </div>

            <SortList v-else :items="chapters" :disabled="!canEdit" @reorder="reorder">
                <template #default="{ item, index }">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="w-6 text-right text-xs tabular-nums text-slate-400">{{ index + 1 }}</span>
                        <input :value="item.name" :disabled="!canEdit" maxlength="200" class="field min-w-0 flex-1 font-medium" :aria-label="t('syllabus.chapter_name')" @change="rename(item, 'name', $event.target.value)" />
                        <label class="flex items-center gap-1 text-xs text-slate-500">{{ t('periods') }}<input v-model="draft[item.id]" :disabled="!canEdit" type="number" min="0" max="500" class="field w-20" @keydown.enter.prevent="dirty && savePeriods()" /></label>
                        <button type="button" class="btn" :aria-expanded="!!open[item.id]" @click="open[item.id] = !open[item.id]">{{ t('topics') }} ({{ item.topics.length }})</button>
                        <button v-if="canEdit" type="button" class="text-sm font-medium text-red-700 hover:underline" @click="removeChapter(item)">{{ t('delete') }}</button>
                    </div>
                    <div v-if="open[item.id]" class="mt-2 border-l-2 border-brand-200 pl-3 sm:ml-8">
                        <SortList :items="item.topics" :disabled="!canEdit" @reorder="reorderTopics(item, $event)">
                            <template #default="{ item: topic }">
                                <div class="flex items-center gap-2">
                                    <input :value="topic.name" :disabled="!canEdit" maxlength="200" class="field min-w-0 flex-1" :aria-label="t('syllabus.topic_name')" @change="renameTopic(topic, $event.target.value)" />
                                    <button v-if="canEdit" type="button" class="text-sm text-red-700 hover:underline" @click="removeTopic(topic)">{{ t('delete') }}</button>
                                </div>
                            </template>
                        </SortList>
                        <form v-if="canEdit" class="mt-2 flex gap-2" @submit.prevent="addTopic(item)">
                            <input v-model="topicText[item.id]" maxlength="200" class="field" :placeholder="t('syllabus.new_topic')" :aria-label="t('syllabus.new_topic')" />
                            <button class="btn">{{ t('add') }}</button>
                        </form>
                    </div>
                </template>
            </SortList>

            <form v-if="canEdit" class="panel mt-4 p-4" @submit.prevent="add">
                <label class="field-label" for="s-lines">{{ t('syllabus.add_title') }}</label>
                <textarea id="s-lines" v-model="lines" rows="4" class="field font-mono text-sm" :placeholder="t('syllabus.add_placeholder')"></textarea>
                <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                    <p class="text-xs text-slate-500">{{ t('syllabus.add_help') }}</p>
                    <button class="btn btn-primary" :disabled="!lines.trim()">{{ t('add') }}</button>
                </div>
            </form>
        </template>

        <BudgetDialog v-if="ask" :ask="ask" :limit="limit" :year="session" :busy="busy" @save="ask.run()" @close="ask = null" />

        <Dialog v-if="dialog === 'copy'" :title="t('syllabus.copy')" @close="dialog = null">
            <label class="field-label" for="c-year">{{ t('syllabus.to_session') }}</label>
            <select id="c-year" v-model="copy.to_year" class="field mb-3"><option v-for="y in nextYears" :key="y" :value="y">{{ y }}</option></select>
            <p class="field-label">{{ t('syllabus.to_classes') }}</p>
            <div class="max-h-48 space-y-1 overflow-auto"><label v-for="c in classNames" :key="c" class="flex items-center gap-2"><input v-model="copy.to_classes" type="checkbox" :value="c" />{{ c }}</label></div>
            <p class="mt-2 text-xs text-slate-500">{{ t('syllabus.copy_help') }}</p>
            <div class="mt-4 flex justify-end gap-2"><button type="button" class="btn" @click="dialog = null">{{ t('cancel') }}</button><button type="button" class="btn btn-primary" :disabled="!copy.to_classes.length" @click="doCopy">{{ t('syllabus.copy') }}</button></div>
        </Dialog>

        <Dialog v-if="dialog === 'import'" :title="t('syllabus.import')" wide @close="dialog = null">
            <template v-if="!imp.task">
                <p class="mb-2">{{ t('syllabus.import_help', { year: session }) }} <a :href="route('teaching.syllabus.template')" class="font-medium text-brand-700 hover:underline">{{ t('syllabus.template') }}</a></p>
                <div class="flex flex-wrap gap-2">
                    <input type="file" accept=".xlsx,.csv" class="field max-w-xs" :aria-label="t('syllabus.file')" @change="imp.file = $event.target.files[0]; imp.preview = null" />
                    <button type="button" class="btn" :disabled="!imp.file || imp.busy" @click="upload">{{ imp.busy ? t('working') : t('syllabus.check') }}</button>
                </div>
                <p v-if="imp.error" class="mt-3 rounded-md border border-red-300 bg-red-50 px-3 py-2 text-red-800" role="alert">{{ imp.error }}</p>
                <template v-if="imp.preview">
                    <p class="mt-3 font-semibold">{{ t('syllabus.preview_counts', { valid: imp.preview.valid, invalid: imp.preview.total - imp.preview.valid }) }}</p>
                    <ul v-if="imp.preview.errors.length" class="mt-2 max-h-28 overflow-auto rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-amber-900"><li v-for="e in imp.preview.errors" :key="e.row">{{ t('syllabus.row') }} {{ e.row }}: {{ e.reason }}</li></ul>
                    <div class="mt-2 max-h-52 overflow-auto rounded-md border border-slate-200">
                        <table class="data-table"><thead><tr><th>{{ t('class') }}</th><th>{{ t('subject') }}</th><th>{{ t('chapter') }}</th><th>{{ t('topic') }}</th><th>{{ t('periods') }}</th></tr></thead>
                            <tbody><tr v-for="(r, i) in imp.preview.sample" :key="i"><td>{{ r.class }}</td><td>{{ r.subject }}</td><td>{{ r.chapter }}</td><td>{{ r.topic }}</td><td>{{ r.periods || '' }}</td></tr></tbody></table>
                    </div>
                    <div class="mt-4 flex justify-end gap-2"><button type="button" class="btn" @click="dialog = null">{{ t('cancel') }}</button><button type="button" class="btn btn-primary" :disabled="!imp.preview.valid || imp.busy" @click="confirmImport">{{ t('syllabus.save_rows', { count: imp.preview.valid }) }}</button></div>
                </template>
            </template>
            <template v-else>
                <TaskProgress :task-id="imp.task" route-name="teaching.tasks.show" @finished="imported" />
                <div class="mt-4 flex justify-end"><button type="button" class="btn" @click="dialog = null">{{ t('close') }}</button></div>
            </template>
        </Dialog>
    </Shell>
</template>
