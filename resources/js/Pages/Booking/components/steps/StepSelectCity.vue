<template>
    <div class="space-y-4">
        <h3 class="text-xl font-semibold text-gray-800">Выберите город</h3>
        <div v-if="isLoading" class="text-sm text-gray-500">
            Загружаем список городов...
        </div>
        <div v-else-if="!cities.length" class="text-sm text-gray-500">
            Города не найдены.
        </div>
        <div v-else class="grid grid-cols-1 gap-3">
            <BaseButton
                v-for="city in cities"
                :key="city.id"
                class="w-full py-2"
                variant="secondary"
                @click="$emit('select', city.id)"
            >
                {{ city.name }}
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
    cities: {
        type: Array,
        default: () => [],
    },
    isLoading: {
        type: Boolean,
        default: false,
    },
})

defineEmits(['select', 'back'])
</script>
