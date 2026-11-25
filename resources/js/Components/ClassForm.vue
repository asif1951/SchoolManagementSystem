<script setup>
/**
 * The one form for creating and editing a class. It is used by the Create Class page, by the create dialog and by the
 * edit dialog of Classes, so all three always ask for the same things.
 *
 * `form` is an Inertia useForm with: className, classTitle, section, sectionTitle, studyGroup, shiftId, mediumId,
 * academicYear, classTeacher, status, subjects (names), maxStudents, description.
 */
import { computed, ref } from 'vue'

const props = defineProps({
    form: { type: Object, required: true },
    mode: { type: String, default: 'create' },          // create | edit
    teachers: { type: Array, default: () => [] },
    subjectOptions: { type: Array, default: () => [] },
    studyGroups: { type: Array, default: () => [] },
    shifts: { type: Array, default: () => [] },
    mediums: { type: Array, default: () => [] },
    submitLabel: { type: String, default: 'Save class' },
})

const emit = defineEmits(['submit', 'cancel'])

const editing = computed(() => props.mode === 'edit')
const err = (key) => props.form.errors?.[key]

// ---- subjects: one list of names; the school's subjects first, then any custom ones already on the class
const newSubject = ref('')
const subjectNames = computed(() => {
    const base = props.subjectOptions.map((s) => s.name)
    const extra = (props.form.subjects || []).filter((n) => !base.some((b) => b.toLowerCase() === String(n).toLowerCase()))

    return [...base, ...extra]
})
const has = (name) => (props.form.subjects || []).some((n) => String(n).toLowerCase() === name.toLowerCase())
const toggle = (name) => {
    props.form.subjects = has(name)
        ? props.form.subjects.filter((n) => String(n).toLowerCase() !== name.toLowerCase())
        : [...(props.form.subjects || []), name]
}
const addCustom = () => {
    const name = newSubject.value.trim()
    if (name && !has(name)) props.form.subjects = [...(props.form.subjects || []), name]
    newSubject.value = ''
}
const selectAll = () => { props.form.subjects = subjectNames.value.slice() }
const clearAll = () => { props.form.subjects = [] }

const classPreview = computed(() => {
    const f = props.form
    const cls = f.classTitle?.trim() ? `${f.classTitle.trim()} (${f.className || '…'})` : (f.className || '')
    const sec = String(f.section || '').split(/[,;\n]+/).map((s) => s.trim()).filter(Boolean)
    const titles = String(f.sectionTitle || '').split(/[,;\n]+/).map((s) => s.trim())
    const secs = sec.map((s, i) => (titles[i] ? `${titles[i]} (${s})` : s))

    return cls ? cls + (secs.length ? ' — ' + secs.join(', ') : '') : ''
})
</script>

<template>
    <form class="space-y-6" novalidate @submit.prevent="emit('submit')">

        <!-- who the class is -->
        <fieldset>
            <legend class="mb-3 text-sm font-semibold text-slate-900 dark:text-white">Class and section</legend>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="field-label" for="cf-name">Class name *</label>
                    <input id="cf-name" v-model="form.className" type="text" class="field" placeholder="e.g. Class 1" />
                    <p v-if="err('className')" class="mt-1 text-xs text-red-600">{{ err('className') }}</p>
                    <p v-else class="mt-1 text-xs text-slate-500">The standard name. It decides the order classes are promoted in.</p>
                </div>
                <div>
                    <label class="field-label" for="cf-title">Class title <span class="font-normal text-slate-400">(optional)</span></label>
                    <input id="cf-title" v-model="form.classTitle" type="text" class="field" placeholder="e.g. Podma" />
                    <p v-if="err('classTitle')" class="mt-1 text-xs text-red-600">{{ err('classTitle') }}</p>
                    <p v-else class="mt-1 text-xs text-slate-500">The name your school gives this class, if it has one.</p>
                </div>
                <div>
                    <label class="field-label" for="cf-section">{{ editing ? 'Section' : 'Section(s)' }} <span class="font-normal text-slate-400">(optional)</span></label>
                    <input id="cf-section" v-model="form.section" type="text" class="field" :placeholder="editing ? 'e.g. A' : 'e.g. A, or A, B, C'" />
                    <p v-if="err('section')" class="mt-1 text-xs text-red-600">{{ err('section') }}</p>
                    <p v-else-if="!editing" class="mt-1 text-xs text-slate-500">Several sections separated by commas make one class for each.</p>
                </div>
                <div>
                    <label class="field-label" for="cf-stitle">{{ editing ? 'Section title' : 'Section title(s)' }} <span class="font-normal text-slate-400">(optional)</span></label>
                    <input id="cf-stitle" v-model="form.sectionTitle" type="text" class="field" :placeholder="editing ? 'e.g. Rose' : 'e.g. Rose, Lily (in the same order)'" />
                    <p v-if="err('sectionTitle')" class="mt-1 text-xs text-red-600">{{ err('sectionTitle') }}</p>
                </div>
            </div>
            <p v-if="classPreview" class="mt-3 rounded-md border border-brand-200 bg-brand-50 px-3 py-2 text-sm text-brand-900">
                Shown as: <span class="font-semibold">{{ classPreview }}</span>
            </p>
        </fieldset>

        <!-- where and when it runs -->
        <fieldset>
            <legend class="mb-3 text-sm font-semibold text-slate-900 dark:text-white">Session, shift and medium</legend>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div>
                    <label class="field-label" for="cf-year">Academic year *</label>
                    <input id="cf-year" v-model="form.academicYear" type="text" inputmode="numeric" maxlength="4" class="field" placeholder="e.g. 2026" />
                    <p v-if="err('academicYear')" class="mt-1 text-xs text-red-600">{{ err('academicYear') }}</p>
                </div>
                <div>
                    <label class="field-label" for="cf-shift">Shift</label>
                    <select id="cf-shift" v-model="form.shiftId" class="field">
                        <option v-for="x in shifts" :key="x.id" :value="x.id">{{ x.name }}</option>
                    </select>
                </div>
                <div>
                    <label class="field-label" for="cf-medium">Medium</label>
                    <select id="cf-medium" v-model="form.mediumId" class="field">
                        <option v-for="x in mediums" :key="x.id" :value="x.id">{{ x.name }}</option>
                    </select>
                </div>
                <div v-if="studyGroups.length">
                    <label class="field-label" for="cf-group">Study group <span class="font-normal text-slate-400">(optional)</span></label>
                    <select id="cf-group" v-model="form.studyGroup" class="field">
                        <option value="">No group</option>
                        <option v-for="g in studyGroups" :key="g.id" :value="g.id">{{ g.name }}</option>
                    </select>
                </div>
            </div>
        </fieldset>

        <!-- who looks after it -->
        <fieldset>
            <legend class="mb-3 text-sm font-semibold text-slate-900 dark:text-white">Class teacher and capacity</legend>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="sm:col-span-1">
                    <label class="field-label" for="cf-teacher">Class teacher *</label>
                    <select id="cf-teacher" v-model="form.classTeacher" class="field">
                        <option value="">Choose a teacher</option>
                        <option v-for="t in teachers" :key="t.id" :value="t.id">{{ t.name }}</option>
                    </select>
                    <p v-if="err('classTeacher')" class="mt-1 text-xs text-red-600">{{ err('classTeacher') }}</p>
                </div>
                <div>
                    <label class="field-label" for="cf-max">Maximum students <span class="font-normal text-slate-400">(optional)</span></label>
                    <input id="cf-max" v-model="form.maxStudents" type="number" min="1" max="200" class="field" />
                    <p v-if="err('maxStudents')" class="mt-1 text-xs text-red-600">{{ err('maxStudents') }}</p>
                </div>
                <div>
                    <label class="field-label" for="cf-status">Status</label>
                    <select id="cf-status" v-model="form.status" class="field">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
        </fieldset>

        <!-- what it studies -->
        <fieldset>
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <legend class="text-sm font-semibold text-slate-900 dark:text-white">Subjects <span class="font-normal text-slate-500">({{ (form.subjects || []).length }} chosen)</span></legend>
                <div class="flex gap-3 text-xs">
                    <button type="button" class="font-medium text-brand-700 hover:underline" @click="selectAll">Select all</button>
                    <button type="button" class="font-medium text-slate-500 hover:underline" @click="clearAll">Clear</button>
                </div>
            </div>
            <p v-if="!subjectNames.length" class="text-sm text-slate-500">No subjects yet: add them in <b>Subjects</b>, or type one below.</p>
            <div class="flex flex-wrap gap-2">
                <button v-for="n in subjectNames" :key="n" type="button" :aria-pressed="has(n)"
                        class="rounded-md border px-3 py-1.5 text-sm transition-colors"
                        :class="has(n) ? 'border-brand-700 bg-brand-700 text-white' : 'border-slate-300 bg-white text-slate-700 hover:border-brand-400 hover:text-brand-800'"
                        @click="toggle(n)">{{ n }}</button>
            </div>
            <div class="mt-3 flex max-w-sm gap-2">
                <input v-model="newSubject" type="text" class="field" placeholder="Another subject" aria-label="Another subject" @keydown.enter.prevent="addCustom" />
                <button type="button" class="btn" @click="addCustom">Add</button>
            </div>
            <p v-if="err('subjects')" class="mt-1 text-xs text-red-600">{{ err('subjects') }}</p>
        </fieldset>

        <div>
            <label class="field-label" for="cf-desc">Notes <span class="font-normal text-slate-400">(optional)</span></label>
            <textarea id="cf-desc" v-model="form.description" rows="2" maxlength="500" class="field"></textarea>
            <p v-if="err('description')" class="mt-1 text-xs text-red-600">{{ err('description') }}</p>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-slate-200 pt-4 dark:border-slate-800">
            <button type="button" class="btn" @click="emit('cancel')">Cancel</button>
            <button type="submit" class="btn btn-primary" :disabled="form.processing">{{ form.processing ? 'Saving…' : submitLabel }}</button>
        </div>
    </form>
</template>
