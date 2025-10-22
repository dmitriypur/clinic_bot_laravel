<template>
    <div class="space-y-4">
        <h3 class="text-xl font-semibold text-gray-800">Выберите дату и время</h3>

        <div class="rounded-lg border border-secondary bg-yellow-50 px-4 py-3 text-sm text-primary space-y-1">
            <p v-if="doctor">
                <span class="font-medium">Доктор:</span> {{ doctor.name }}
            </p>
            <p v-if="clinicName">
                <span class="font-medium">Клиника:</span> {{ clinicName }}
            </p>
            <p v-if="branchName">
                <span class="font-medium">Филиал:</span> {{ branchName }}
            </p>
        </div>

        <Datepicker
            v-model="internalDate"
            :min-date="minDate"
            :enable-time-picker="false"
            :auto-apply="true"
            :locale="datepickerLocale"
            :week-start="1"
            :timezone="timezone"
        />

        <div>
            <p class="text-sm text-gray-600 mb-2">
                Свободные слоты на {{ selectedDateLabel }}
            </p>
            <div v-if="isLoadingSlots" class="text-sm text-gray-500">
                Загружаем слоты...
            </div>
            <div v-else-if="slots.length === 0" class="text-sm text-gray-500">
                Смены врача на эту дату отсутствуют. Попробуйте выбрать другую дату.
            </div>
            <div v-else class="grid grid-cols-2 gap-2">
                <button
                    v-for="slot in slots"
                    :key="slot.id || slot.datetime"
                    type="button"
                    @click="$emit('select-slot', slot)"
                    :class="[
                        'py-2 rounded-lg border text-center transition',
                        !slot.is_available
                            ? 'border-gray-200 bg-gray-100 text-gray-400 cursor-not-allowed'
                            : (selectedSlot && selectedSlot.datetime === slot.datetime
                                ? 'border-secondary bg-yellow-50 text-primary'
                                : 'border-gray-200 hover:bg-indigo-100')
                    ]"
                    :disabled="!slot.is_available"
                >
                    <span class="font-medium">{{ slot.time }}</span>
                    <span v-if="slot.is_past" class="block text-[10px] uppercase tracking-wide text-gray-400">Прошло</span>
                    <span v-else-if="slot.is_occupied" class="block text-[10px] uppercase tracking-wide text-red-500">Занято</span>
                </button>
            </div>
            <p v-if="slots.length > 0 && !hasAvailableSlots" class="text-xs text-amber-600 mt-2">
                Все слоты на выбранную дату заняты или уже прошли. Попробуйте выбрать другое число.
            </p>
        </div>

        <BaseButton
            v-if="selectedSlot"
            class="w-full py-2"
            variant="primary"
            @click="$emit('next')"
        >
            Продолжить
        </BaseButton>

        <BaseButton variant="ghost" class="text-sm" @click="$emit('back')">
            ← Назад
        </BaseButton>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import Datepicker from '@vuepic/vue-datepicker'
import '@vuepic/vue-datepicker/dist/main.css'

import BaseButton from '../../../../Components/ui/BaseButton.vue'

const props = defineProps({
    selectedDate: {
        type: [Date, String],
        required: true,
    },
    minDate: {
        type: [Date, String],
        required: true,
    },
    slots: {
        type: Array,
        default: () => [],
    },
    selectedSlot: {
        type: Object,
        default: null,
    },
    isLoadingSlots: {
        type: Boolean,
        default: false,
    },
    hasAvailableSlots: {
        type: Boolean,
        default: false,
    },
    selectedDateLabel: {
        type: String,
        default: '',
    },
    datepickerLocale: {
        type: Object,
        required: true,
    },
    timezone: {
        type: [String, Object],
        default: 'Europe/Moscow',
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

const emits = defineEmits(['update:selectedDate', 'change-date', 'select-slot', 'next', 'back'])

const internalDate = computed({
    get: () => props.selectedDate,
    set: (value) => {
        emits('update:selectedDate', value)
        emits('change-date', value)
    },
})
</script>
