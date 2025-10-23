<template>
    <div class="space-y-4">
        <h3 class="text-xl font-semibold text-gray-800">Дата рождения (необязательно)</h3>
        <Datepicker
            v-model="internalValue"
            class="booking-picker"
            :inline="true"
            :min-date="minDate"
            :enable-time-picker="false"
            :auto-apply="true"
            :locale="datepickerLocale"
            :week-start="1"
            :timezone="timezone"
            :month-change-on-scroll="false"
        />
        <div class="flex gap-2">
            <BaseButton class="flex-1 py-2" variant="primary" @click="$emit('next')">
                Продолжить
            </BaseButton>
            <BaseButton class="flex-1 py-2" variant="secondary" @click="$emit('skip')">
                Пропустить
            </BaseButton>
        </div>
        <BaseButton variant="ghost" class="text-sm" @click="$emit('back')">
            ← Назад
        </BaseButton>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import BaseButton from '../../../../Components/ui/BaseButton.vue'
import Datepicker from '@vuepic/vue-datepicker'
import '@vuepic/vue-datepicker/dist/main.css'

const props = defineProps({
    modelValue: {
        type: String,
        default: null,
    },
    datepickerLocale: {
        type: Object,
        required: true,
    },
})

const emits = defineEmits(['update:modelValue', 'next', 'skip', 'back'])

const internalValue = computed({
    get: () => props.modelValue,
    set: (value) => emits('update:modelValue', value),
})
</script>

<style scoped>

:deep(.booking-picker) {
    width: 100%;
    flex-direction: column;

    div{
        width: 100%;
    }

    .dp__theme_light{
        --dp-background-color: #fff8e6;
        --dp-text-color: #1f2937;
        --dp-primary-color: #f59e0b !important;
        --dp-hover-color: #fde68a;
        --dp-highlight-color: #f97316;
        --dp-active-text-color: #0f172a;
        --dp-border-radius: 12px;
        --dp-input-padding: 12px;
    }

}

</style>
