<template>
    <div class="space-y-4">
        <h3 class="text-xl font-semibold text-gray-800">Доктора</h3>

        <p class="text-sm text-gray-500">
            С выбранными условиями найдено докторов:
            <span
                class="font-bold"
                :class="doctors.length > 0 ? 'text-green-500' : 'text-red-500'"
            >
                {{ doctors.length }}
            </span>
        </p>

        <div v-if="isLoading" class="text-sm text-gray-500">
            Загружаем список докторов...
        </div>
        <div v-else-if="!doctors.length" class="text-sm text-gray-500">
            Докторов по заданным параметрам не найдено. Попробуйте изменить фильтры.
        </div>
        <div v-else class="grid grid-cols-1 gap-3">
            <BaseButton
                v-for="doctor in doctors"
                :key="doctor.id"
                class="w-full py-2"
                :variant="selectedDoctorId === doctor.id ? 'primary' : 'secondary'"
                @click="$emit('select', doctor)"
            >
                {{ doctor.name }}
            </BaseButton>
        </div>

        <BaseButton variant="ghost" class="text-sm" @click="$emit('back')">
            ← Назад
        </BaseButton>
    </div>
</template>

<script setup>
import BaseButton from '../../../../Components/ui/BaseButton.vue'

defineProps({
    doctors: {
        type: Array,
        default: () => [],
    },
    selectedDoctorId: {
        type: [Number, String, null],
        default: null,
    },
    isLoading: {
        type: Boolean,
        default: false,
    },
})

defineEmits(['select', 'back'])
</script>
