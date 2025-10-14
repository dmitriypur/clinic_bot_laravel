<template>
    <div class="space-y-4">
        <h3 class="text-xl font-semibold text-gray-800">Форма записи</h3>

        <div v-if="selectedSlot" class="rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-700 space-y-1">
            <p><span class="font-medium">Дата:</span> {{ selectedDateLabel }}</p>
            <p><span class="font-medium">Время:</span> {{ selectedSlot.time }}</p>
            <p v-if="doctor"><span class="font-medium">Доктор:</span> {{ doctor.name }}</p>
            <p v-if="clinicName"><span class="font-medium">Клиника:</span> {{ clinicName }}</p>
            <p v-if="branchName"><span class="font-medium">Филиал:</span> {{ branchName }}</p>
            <p v-if="selectedSlot.cabinet_name"><span class="font-medium">Кабинет:</span> {{ selectedSlot.cabinet_name }}</p>
        </div>

        <form class="space-y-3" @submit.prevent="$emit('submit')">
            <BaseInput
                v-model="fioProxy"
                placeholder="ФИО"
            />
            <BaseInput
                v-model="phoneProxy"
                placeholder="Телефон"
            />
            <BaseButton
                class="w-full py-2"
                variant="primary"
                type="submit"
            >
                Записаться
            </BaseButton>
        </form>

        <BaseButton variant="ghost" class="text-sm" @click="$emit('back')">
            ← Назад
        </BaseButton>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import BaseButton from '../../../../Components/ui/BaseButton.vue'
import BaseInput from '../../../../Components/ui/BaseInput.vue'

const props = defineProps({
    selectedSlot: {
        type: Object,
        default: null,
    },
    selectedDateLabel: {
        type: String,
        default: '',
    },
    fio: {
        type: String,
        default: '',
    },
    phone: {
        type: String,
        default: '',
    },
    doctor: {
        type: Object,
        default: null,
    },
    clinicName: {
        type: String,
        default: '',
    },
    branchName: {
        type: String,
        default: '',
    },
})

const emits = defineEmits(['update:fio', 'update:phone', 'submit', 'back'])

const fioProxy = computed({
    get: () => props.fio,
    set: (value) => emits('update:fio', value),
})

const phoneProxy = computed({
    get: () => props.phone,
    set: (value) => emits('update:phone', value),
})
</script>
