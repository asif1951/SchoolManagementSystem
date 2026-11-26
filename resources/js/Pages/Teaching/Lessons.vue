<script setup>
import Shell from '@/Components/Teaching/Shell.vue'
import Dialog from '@/Components/Teaching/Dialog.vue'
import { Link, router } from '@inertiajs/vue3'
import { onMounted, reactive, ref, watch } from 'vue'
import { useT } from '@/composables/useT'

const props = defineProps({ tab: String, q: String, lessons: Object, targets: Array, prefill: Object, canWrite: Boolean, flash: Object })
const t = useT('teaching')
const texts = ['objectives', 'outcomes', 'method', 'materials', 'assessment', 'homework']

const search = ref(props.q)
let timer = null
const load = (more = {}) => router.get(route('teaching.lessons'), { tab: props.tab, q: search.value || undefined, ...more }, { preserveState: true, preserveScroll: true, replace: true })
watch(search, () => { clearTimeout(timer); timer = setTimeout(load, 300) })

const empty = () => ({ id: null, log_id: null, title: '', class_id: null, subject_id: null, chapter_id: null, topic_id: null, lesson_date: null, duration: 40, is_template: false, objectives: '', outcomes: '', method: '', materials: '', assessment: '', homework: '' })
const form = reactive(empty())
const target = ref('')
const editing = ref(false)
const edit = (l = null) => {
    Object.assign(form, empty(), l ? Object.fromEntries(Object.keys(empty()).map((k) => [k, l[k] ?? empty()[k]])) : {})
    target.value = form.subject_id ? `${form.class_id ?? ''}:${form.subject_id}` : ''
    editing.value = true
}
watch(target, (v) => { const [c, s] = v.split(':'); form.class_id = c ? Number(c) : null; form.subject_id = s ? Number(s) : null })
onMounted(() => { if (props.prefill) edit(props.prefill) })
const busy = ref(false)
const save = () => {
    busy.value = true
    const options = { preserveScroll: true, onSuccess: () => { editing.value = false }, onFinish: () => { busy.value = false } }
    if (form.id) router.put(route('teaching.lessons.update', form.id), { ...form }, options)
    else router.post(route('teaching.lessons.store'), { ...form }, options)
}

const copying = ref(null)
const chosen = ref([])
const doCopy = () => router.post(route('teaching.lessons.copy', copying.value.id), { class_ids: chosen.value }, { preserveScroll: true, onSuccess: () => { copying.value = null } })
const deleting = ref(null)
const remove = () => router.delete(route('teaching.lessons.destroy', deleting.value.id), { preserveScroll: true, onSuccess: () => { deleting.value = null } })
</script>

<template>
    <Shell :title="t('nav.lessons')" :subtitle="t('lesson.subtitle')">
        <template #actions><button v-if="canWrite" type="button" class="btn btn-primary" @click="edit()">+ {{ t('lesson.new') }}</button></template>

        <section class="panel mb-4 flex flex-wrap items-center gap-3 p-3">
            <div class="flex gap-1" role="tablist">
                <button v-for="x in ['lessons', 'templates']" :key="x" type="button" role="tab" :aria-selected="tab === x" class="rounded px-3 py-1.5 text-sm font-medium" :class="tab === x ? 'bg-brand-700 text-white' : 'text-slate-600 hover:bg-slate-100'" @click="load({ tab: x })">{{ t(`lesson.${x}`) }}</button>
            </div>
            <input v-model="search" type="search" class="field w-64" :placeholder="t('search')" :aria-label="t('search')" />
        </section>

        <div v-if="!lessons.data.length" class="panel px-4 py-10 text-center text-sm text-slate-500">
            <p>{{ t('lesson.empty') }}</p>
            <button v-if="canWrite" type="button" class="btn btn-primary mt-3" @click="edit()">{{ t('lesson.new') }}</button>
        </div>

        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
            <article v-for="l in lessons.data" :key="l.id" class="panel p-4">
                <h2 class="font-semibold text-slate-900 dark:text-white">{{ l.title }}</h2>
                <p class="text-sm text-slate-500">{{ [l.subject, l.class, l.chapter, l.lesson_date, l.owner].filter(Boolean).join(' · ') }}</p>
                <p v-if="l.objectives" class="mt-2 line-clamp-2 text-sm">{{ l.objectives }}</p>
                <div class="mt-3 flex flex-wrap gap-3 text-sm font-medium">
                    <button v-if="l.mine" type="button" class="text-brand-700 hover:underline" @click="edit(l)">{{ t('edit') }}</button>
                    <button v-if="canWrite && (l.mine || l.is_template)" type="button" class="text-brand-700 hover:underline" @click="chosen = []; copying = l">{{ l.is_template ? t('lesson.use') : t('lesson.copy') }}</button>
                    <a :href="route('teaching.lessons.print', l.id)" target="_blank" rel="noopener" class="text-slate-600 hover:underline">{{ t('print') }}</a>
                    <button v-if="l.mine" type="button" class="ml-auto text-red-700 hover:underline" @click="deleting = l">{{ t('delete') }}</button>
                </div>
            </article>
        </div>
        <nav v-if="lessons.prev || lessons.next" class="mt-4 flex justify-center gap-3"><Link v-if="lessons.prev" :href="lessons.prev" class="btn">← {{ t('previous') }}</Link><Link v-if="lessons.next" :href="lessons.next" class="btn">{{ t('next') }} →</Link></nav>

        <Dialog v-if="editing" :title="form.id ? t('lesson.edit') : t('lesson.new')" wide @close="editing = false">
            <form class="space-y-3" @submit.prevent="save">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="sm:col-span-2"><label class="field-label" for="l-title">{{ t('lesson.topic') }}</label><input id="l-title" v-model="form.title" required maxlength="200" class="field" /></div>
                    <div class="sm:col-span-2"><label class="field-label" for="l-target">{{ t('subject') }} · {{ t('class') }}</label><select id="l-target" v-model="target" required class="field"><option value="">—</option><option v-for="x in targets" :key="`${x.class_id}:${x.subject_id}`" :value="`${x.class_id}:${x.subject_id}`">{{ x.label }}</option></select></div>
                    <div><label class="field-label" for="l-date">{{ t('date') }}</label><input id="l-date" v-model="form.lesson_date" type="date" class="field" /></div>
                    <div><label class="field-label" for="l-dur">{{ t('lesson.duration') }}</label><input id="l-dur" v-model="form.duration" type="number" min="5" max="600" class="field" /></div>
                    <div v-for="f in texts" :key="f"><label class="field-label" :for="`l-${f}`">{{ t(`lesson.${f}`) }}</label><textarea :id="`l-${f}`" v-model="form[f]" rows="2" maxlength="5000" class="field"></textarea></div>
                </div>
                <label class="flex items-center gap-2"><input v-model="form.is_template" type="checkbox" />{{ t('lesson.as_template') }}</label>
                <div class="flex justify-end gap-2"><button type="button" class="btn" @click="editing = false">{{ t('cancel') }}</button><button class="btn btn-primary" :disabled="busy">{{ t('save') }}</button></div>
            </form>
        </Dialog>

        <Dialog v-if="copying" :title="t('lesson.copy')" @close="copying = null">
            <div class="max-h-60 space-y-1 overflow-auto">
                <label v-for="x in targets.filter((y) => y.subject_id === copying.subject_id && y.class_id !== copying.class_id)" :key="x.class_id" class="flex items-center gap-2"><input v-model="chosen" type="checkbox" :value="x.class_id" />{{ x.label }}</label>
                <p v-if="!targets.some((y) => y.subject_id === copying.subject_id && y.class_id !== copying.class_id)" class="text-slate-500">{{ t('lesson.no_target') }}</p>
            </div>
            <div class="mt-4 flex justify-end gap-2"><button type="button" class="btn" @click="copying = null">{{ t('cancel') }}</button><button type="button" class="btn btn-primary" :disabled="!chosen.length" @click="doCopy">{{ t('lesson.copy') }}</button></div>
        </Dialog>

        <Dialog v-if="deleting" :title="t('delete')" @close="deleting = null">
            <p>{{ t('lesson.delete_confirm', { title: deleting.title }) }}</p>
            <div class="mt-4 flex justify-end gap-2"><button type="button" class="btn" @click="deleting = null">{{ t('cancel') }}</button><button type="button" class="btn btn-danger" @click="remove">{{ t('delete') }}</button></div>
        </Dialog>
    </Shell>
</template>
